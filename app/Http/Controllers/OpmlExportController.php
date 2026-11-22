<?php

namespace App\Http\Controllers;

use App\Services\Feeds\OpmlService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OpmlExportController extends Controller
{
    public function __invoke(Request $request, OpmlService $opml): Response
    {
        return response($opml->export($request->user()), 200, [
            'Content-Type' => 'text/x-opml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="newsstand-'.now()->format('Y-m-d').'.opml"',
        ]);
    }
}
