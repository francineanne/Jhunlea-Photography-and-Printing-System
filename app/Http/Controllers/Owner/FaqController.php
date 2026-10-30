<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Faqs\DeleteFaqRequest;
use App\Http\Requests\Owner\Faqs\MoveFaqRequest;
use App\Http\Requests\Owner\Faqs\PublishFaqRequest;
use App\Http\Requests\Owner\Faqs\StoreFaqRequest;
use App\Http\Requests\Owner\Faqs\UpdateFaqRequest;
use App\Models\Faq;
use App\Services\FaqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The owner's FAQ editor (Phase 6B). Every change goes through FaqService; every button is a plain form (no JavaScript needed). */
class FaqController extends Controller
{
    public function __construct(private readonly FaqService $faqs) {}

    public function index(): View
    {
        $items = Faq::query()->ordered()->get();

        return view('owner.faqs.index', ['faqs' => $items, 'full' => $items->count() >= Faq::MAX_ITEMS]);
    }

    public function create(): View|RedirectResponse
    {
        if (Faq::query()->count() >= Faq::MAX_ITEMS) {
            return redirect()->route('owner.faqs.index')->with('error', 'The FAQ can hold up to '.Faq::MAX_ITEMS.' questions. Delete one to add another.');
        }

        return view('owner.faqs.create', ['faq' => new Faq(['is_published' => true])]);
    }

    public function store(StoreFaqRequest $request): RedirectResponse
    {
        try {
            $this->faqs->create($request->faq());
        } catch (ValidationException $e) {
            return redirect()->route('owner.faqs.create')->withErrors($e->errors())->withInput();
        }

        return redirect()->route('owner.faqs.index')->with('status', 'Question added at the end of the list.');
    }

    public function edit(Faq $faq): View
    {
        return view('owner.faqs.edit', ['faq' => $faq]);
    }

    public function update(UpdateFaqRequest $request, Faq $faq): RedirectResponse
    {
        try {
            $this->faqs->update($faq, $request->faq());
        } catch (ValidationException $e) {
            return redirect()->route('owner.faqs.edit', $faq)->withErrors($e->errors())->withInput();
        }

        return redirect()->route('owner.faqs.index')->with('status', 'Question saved.');
    }

    public function publish(PublishFaqRequest $request, Faq $faq): RedirectResponse
    {
        $this->faqs->setPublished($faq, $request->published());

        return redirect()->route('owner.faqs.index')->with('status', $request->published() ? 'The question is shown on the FAQ page.' : 'The question is hidden from the FAQ page.');
    }

    public function move(MoveFaqRequest $request, int $faq): RedirectResponse
    {
        $moved = $this->faqs->move($faq, $request->step(), $request->from());

        return redirect()->route('owner.faqs.index')->with('status', $moved ? 'Order saved.' : 'Nothing moved: the list had already changed.');
    }

    /** The confirm step when JavaScript is off; with JavaScript the list opens a confirm dialog instead. */
    public function confirmDelete(Faq $faq): View
    {
        return view('owner.faqs.delete', ['faq' => $faq]);
    }

    public function destroy(DeleteFaqRequest $request, int $faq): RedirectResponse
    {
        $deleted = $this->faqs->delete($faq);

        return redirect()->route('owner.faqs.index')->with('status', $deleted ? 'Question deleted.' : 'That question was already deleted.');
    }
}
