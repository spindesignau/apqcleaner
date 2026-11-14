<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Changelog;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function __invoke(Changelog $changelog): View
    {
        return view('admin.changelog', ['html' => $changelog->html(), 'version' => $changelog->version()]);
    }
}
