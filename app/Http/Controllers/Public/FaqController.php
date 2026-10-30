<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\View\View;

/** The public FAQ page: the published questions only, in the owner's order. */
class FaqController extends Controller
{
    public function __invoke(): View
    {
        return view('public.faq', ['faqs' => Faq::query()->published()->ordered()->get(['id', 'question', 'answer'])]);
    }
}
