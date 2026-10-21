<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimProofImagesController extends Controller
{
    public function owner(Request $request, int $item, ImageFiles $files)
    {
        return $this->content($item, (int) $request->user()->person_id, $files);
    }

    public function staff(int $item, int $claimant, ImageFiles $files)
    {
        return $this->content($item, $claimant, $files);
    }

    private function content(int $item, int $claimant, ImageFiles $files)
    {
        $row = DB::table('claim_proof_image')->where('item_id', $item)->where('claimant_id', $claimant)->first();
        abort_unless($row, 404);

        return response($files->content($row->path))
            ->header('Content-Type', 'image/jpeg')->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }
}
