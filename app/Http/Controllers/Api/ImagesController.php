<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ImagesController extends Controller
{
    private function scope(Request $request): array
    {
        return match ($request->route('scope')) {
            'finder' => ['finder_report', 'report_id', 'finder_report_id', 'PENDING'],
            'missing' => ['missing_item_report', 'report_id', 'missing_report_id', 'OPEN'],
            'item' => ['found_item', 'item_id', 'item_id', null],
        };
    }

    private function parent(Request $request, int $parent, bool $write = false): string
    {
        [$table, $key, $foreign, $state] = $this->scope($request);
        $query = DB::table($table)->where($key, $parent);
        if ($request->route('audience') === 'owner') {
            $query->where('user_id', $request->user()->id);
        }
        if ($write) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row, 404);
        if ($write && $state && $row->status !== $state) {
            throw new ConflictHttpException('Images can only be changed on an active report.');
        }
        if ($write && ! $state && (! DB::table('storage_assignment')->where('item_id', $parent)->whereNull('removed_at')->exists() || DB::table('claim')->where('item_id', $parent)->where('outcome', 'RETURNED')->exists())) {
            throw new ConflictHttpException('Images can only be changed on a currently stored item.');
        }

        return $foreign;
    }

    private function metadata(object $row): array
    {
        return ['image_id' => $row->image_id, 'width' => $row->width, 'height' => $row->height, 'bytes' => $row->bytes, 'approved' => $row->approved_at !== null, 'updated_at' => $row->updated_at];
    }

    public function index(Request $request, int $parent)
    {
        $foreign = $this->parent($request, $parent);

        return response()->json(['data' => DB::table('image_attachment')->where($foreign, $parent)->orderBy('image_id')->get()->map(fn ($row) => $this->metadata($row))])->header('Cache-Control', 'private, no-store');
    }

    public function content(Request $request, int $parent, int $image, ImageFiles $files)
    {
        $foreign = $this->parent($request, $parent);
        $row = DB::table('image_attachment')->where($foreign, $parent)->where('image_id', $image)->first();
        abort_unless($row, 404);

        return $this->bytes($files->content($row->path));
    }

    public function save(Request $request, int $parent, ImageFiles $files)
    {
        $image = $request->route('image') === null ? null : (int) $request->route('image');
        // Authorize before decoding or writing any supplied file.
        $this->parent($request, $parent);
        $request->validate(['image' => ['required', 'file', 'max:5120']]);
        $normalized = $files->normalize($request->file('image'));
        $path = $files->newPath();
        $oldPath = null;
        try {
            $files->put($path, $normalized['content']);
            $row = DB::transaction(function () use ($request, $parent, $image, $normalized, $path, &$oldPath) {
                $foreign = $this->parent($request, $parent, true);
                $query = DB::table('image_attachment')->where($foreign, $parent);
                if ($image !== null) {
                    $old = (clone $query)->where('image_id', $image)->first();
                    abort_unless($old, 404);
                    $oldPath = $old->path;
                } elseif ($query->count() >= 5) {
                    throw new ConflictHttpException('A report or item can have at most five images.');
                }
                $data = [$foreign => $parent, 'path' => $path, 'width' => $normalized['width'], 'height' => $normalized['height'], 'bytes' => $normalized['bytes'], 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()];
                if ($image !== null) {
                    DB::table('image_attachment')->where('image_id', $image)->update($data);
                } else {
                    $image = DB::table('image_attachment')->insertGetId($data + ['created_at' => now()], 'image_id');
                }

                return DB::table('image_attachment')->where('image_id', $image)->first();
            });
        } catch (\Throwable $error) {
            // A lost commit acknowledgement is ambiguous: keep the file if a reference
            // exists or the database cannot confirm rollback. Orphans are safer than broken references.
            try {
                if (! DB::table('image_attachment')->where('path', $path)->exists()) {
                    $files->remove($path);
                }
            } catch (\Throwable) {
                Log::warning('Image commit could not be confirmed; retain file for reconciliation', ['path' => $path]);
            }
            throw $error;
        }
        if ($oldPath) {
            DB::afterCommit(fn () => $files->remove($oldPath));
        }

        return response()->json(['data' => $this->metadata($row)], $oldPath ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $parent, int $image, ImageFiles $files)
    {
        DB::transaction(function () use ($request, $parent, $image, $files): void {
            $foreign = $this->parent($request, $parent, true);
            $row = DB::table('image_attachment')->where($foreign, $parent)->where('image_id', $image)->first();
            abort_unless($row, 404);
            DB::table('image_attachment')->where('image_id', $image)->delete();
            DB::afterCommit(fn () => $files->remove($row->path));
        });

        return response()->noContent();
    }

    public function publication(Request $request, int $parent, int $image)
    {
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $row = DB::transaction(function () use ($request, $parent, $image, $data) {
            $this->parent($request, $parent, true);
            $query = DB::table('image_attachment')->where('item_id', $parent)->where('image_id', $image);
            abort_unless((clone $query)->exists(), 404);
            $query->update(['approved_by' => $data['approved'] ? $request->user()->staff_id : null, 'approved_at' => $data['approved'] ? now() : null, 'updated_at' => now()]);

            return $query->first();
        });

        return response()->json(['data' => $this->metadata($row)])->header('Cache-Control', 'private, no-store');
    }

    public function publicContent(int $item, int $image, ImageFiles $files)
    {
        $visible = DB::table('public_item_listing')->where('item_id', $item)->whereNull('unpublished_at')->exists()
            && DB::table('storage_assignment')->where('item_id', $item)->whereNull('removed_at')->exists()
            && ! DB::table('claim')->where('item_id', $item)->where('outcome', 'RETURNED')->exists();
        abort_unless($visible, 404);
        $row = DB::table('image_attachment')->where('item_id', $item)->where('image_id', $image)->whereNotNull('approved_at')->first();
        abort_unless($row, 404);

        return $this->bytes($files->content($row->path));
    }

    private function bytes(string $bytes)
    {
        return response($bytes)->header('Content-Type', 'image/jpeg')->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    public static function publicMetadata(array $items): Collection
    {
        return DB::table('image_attachment')->whereIn('item_id', $items)->whereNotNull('approved_at')->orderBy('image_id')->get(['item_id', 'image_id', 'width', 'height'])->groupBy('item_id')->map(fn ($rows) => $rows->map(fn ($row) => ['image_id' => $row->image_id, 'width' => $row->width, 'height' => $row->height]));
    }
}
