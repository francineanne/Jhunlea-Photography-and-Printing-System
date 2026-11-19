<?php

namespace App\Http\Controllers\Owner;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\LumiStatus;
use App\Services\ShopSettingsService;
use App\Support\Lumi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 10E: Settings > Lumi assistant. The owner's on/off switch and the text customers read when Lumi cannot answer
 * (saved through ShopSettingsService, audited like every setting), and "Test Lumi": one short fixed question through
 * the real provider chain (it counts against the daily budget like any question), answered with which provider
 * replied and how long it took, or the kind of failure. Never a key, a prompt or an error body.
 */
class LumiSettingsController extends Controller
{
    public const FALLBACK_MAX = 300;

    public function update(Request $request, ShopSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'fallback_text' => ['nullable', 'string', 'max:'.self::FALLBACK_MAX, 'not_regex:/[\x00-\x1F\x7F]/u'],
            'reset_fallback' => ['nullable', 'boolean'],
        ], [
            'fallback_text.max' => 'Keep the message to :max characters.',
            'fallback_text.*' => 'Type the message as plain text on one line.',
        ]);

        $text = $request->boolean('reset_fallback') ? null : (filled($validated['fallback_text'] ?? null) ? trim((string) $validated['fallback_text']) : null);
        $settings->saveLumi($request->boolean('enabled'), $text);

        return redirect()->to(route('owner.settings.index').'#lumi')->with('status', $request->boolean('enabled')
            ? 'Lumi settings saved. Lumi answers customers when you are away.'
            : 'Lumi is turned off. Customers see your message and can still write to the studio.');
    }

    public function test(Request $request, AssistantEngine $engine): RedirectResponse
    {
        $back = redirect()->to(route('owner.settings.index').'#lumi');

        if (! Lumi::on()) {
            return $back->with('warning', Lumi::offInSettings() ? 'Lumi is turned off in Settings, so nothing was sent. Turn it on to test it.' : 'Lumi is turned off for this website, so nothing was sent. Ask the developer to turn it on.');
        }

        $start = Carbon::now();
        $result = $engine->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::user((int) $request->user()->id), LumiStatus::TEST_QUESTION));
        $ms = LumiStatus::since($start);

        if ($result->fromAssistant()) {
            return $back->with('status', 'Lumi answered in '.number_format($ms / 1000, 1).' s, by '.LumiStatus::providerName($result->provider, $result->viaBackup).'.');
        }

        return $back->with('error', 'Lumi could not answer: '.self::reason($result->outcome).' (after '.number_format($ms / 1000, 1).' s). Customers see your fallback message.');
    }

    /** Phase 10H: why the test failed, in plain words (never the raw outcome code). */
    private static function reason(AssistantOutcome $outcome): string
    {
        return match ($outcome) {
            AssistantOutcome::FallbackUnavailable => 'the AI service did not respond',
            AssistantOutcome::FallbackLimitUser => 'too many questions were asked for now',
            AssistantOutcome::FallbackLimitGlobal => "today's question budget is used up",
            AssistantOutcome::FallbackDisabled => 'it is turned off',
            AssistantOutcome::FallbackError => 'its answer could not be used',
            default => 'it did not give a normal answer',
        };
    }
}
