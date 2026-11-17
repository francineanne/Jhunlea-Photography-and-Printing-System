@extends('layouts.public')

@section('title', 'Terms and privacy | '.config('app.name'))

{{--
    Phase 10C: the page the registration checkbox links to. Short and honest: only what the system really does. The
    contact lines appear only when the owner filled them in on the Settings page.
--}}
<x-seo
    :title="'Terms and privacy | '.config('app.name')"
    :description="'How '.config('app.name').' handles your requests and your personal data under the Data Privacy Act of 2012 (RA 10173).'" />

@php($shopName = $shop['shop_name'] ?: config('app.name'))

@section('main')
    <section class="mx-auto max-w-[1280px] px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
        <nav aria-label="Breadcrumb" class="mb-6 text-xs font-semibold uppercase tracking-wider text-app-text-2">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="inline-flex min-h-11 min-w-11 items-center rounded hover:text-brand focus-ring">Home</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-brand">Terms and privacy</li>
            </ol>
        </nav>

        <header class="max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-widest text-brand">The fine print, in plain words</p>
            <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-5xl">Terms and privacy</h1>
            <p class="mt-3 text-app-text-2">How {{ $shopName }} handles your requests and your personal data, under the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
        </header>

        <div class="mt-8 max-w-3xl space-y-6 leading-relaxed text-app-text-2 [&_h2]:font-display [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-app-text [&_li]:ml-5 [&_li]:list-disc">
            <x-ui.card class="space-y-3">
                <h2>Using the shop's system</h2>
                <ul class="space-y-1.5">
                    <li>Sending a printing order or a booking request does not mean it is accepted. The shop reviews it first.</li>
                    <li>A requested time is not a confirmed appointment until the shop confirms it.</li>
                    <li>An estimated price is only an estimate. The shop confirms the final price.</li>
                    <li>Payment is made face-to-face at {{ $shopName }} (cash, or GCash checked by the shop). The system only records payments; it never takes money online.</li>
                    @if ($downpaymentPercent > 0)
                        <li>A photoshoot may need a {{ $downpaymentPercent }}% downpayment by a deadline. A recorded downpayment is not refunded when you cancel.</li>
                    @endif
                    <li>Only upload files you have the right to print. The shop may refuse files it cannot print.</li>
                    <li>Lumi is an AI assistant. Its answers are suggestions, not bookings or quotations.</li>
                </ul>
            </x-ui.card>

            <x-ui.card class="space-y-3">
                <h2>What we collect and why</h2>
                <ul class="space-y-1.5">
                    <li><strong class="text-app-text">Your account:</strong> name, email address, mobile number, your password (stored only in a scrambled form nobody can read) and, if you add one, a profile picture. Used to sign you in and to reach you about your requests.</li>
                    <li><strong class="text-app-text">Your requests:</strong> printing orders and the files you upload, booking details (date, package, people, contact number, notes, reference images). Used to do the work you asked for.</li>
                    <li><strong class="text-app-text">Payments:</strong> the amount, the date, the method and a GCash reference number when you paid by GCash. Used to keep the shop's records correct.</li>
                    <li><strong class="text-app-text">Messages:</strong> what you write to the studio and to Lumi. Used to answer you.</li>
                    <li><strong class="text-app-text">Security records:</strong> a sign-in cookie and limited records of important actions in the shop. Used to keep accounts safe.</li>
                </ul>
                <p>We do not sell your data and we do not use it for advertising.</p>
            </x-ui.card>

            <x-ui.card class="space-y-3">
                <h2>Who sees it</h2>
                <ul class="space-y-1.5">
                    <li>You see only your own requests, files and messages.</li>
                    <li>The shop owner sees what is needed to serve you.</li>
                    <li>When you chat with Lumi, your message is sent to an AI service provider to write the reply. Email addresses and phone numbers are removed from it first. Please do not type private details there.</li>
                    <li>Emails (such as your verification link or a password code) are sent through an email service.</li>
                </ul>
            </x-ui.card>

            <x-ui.card class="space-y-3">
                <h2>How long we keep it</h2>
                <ul class="space-y-1.5">
                    <li>Your account and requests are kept while your account exists, so you can follow your history.</li>
                    <li>Payment records are kept for the shop's records, even after a request is closed.</li>
                    <li>The records of important actions are kept for about {{ intdiv(max($auditDays, 1), 365) ?: 1 }} {{ intdiv(max($auditDays, 1), 365) > 1 ? 'years' : 'year' }}.</li>
                    <li>A password code is deleted once it is used or after 10 minutes.</li>
                </ul>
            </x-ui.card>

            <x-ui.card class="space-y-3">
                <h2>Your rights and how to ask</h2>
                <p>Under the Data Privacy Act you may ask to see the data we keep about you, to correct it, or to have it deleted. You can correct your name, email and mobile number yourself on your account page. For anything else, including deleting your account, message the studio in the chat or contact the shop:</p>
                @if (filled($shop['email']) || filled($shop['phone']) || filled($shop['address']))
                    <ul class="space-y-1.5">
                        @if (filled($shop['email']))<li>Email: {{ $shop['email'] }}</li>@endif
                        @if (filled($shop['phone']))<li>Phone: {{ $shop['phone'] }}</li>@endif
                        @if (filled($shop['address']))<li>Visit: {{ $shop['address'] }}</li>@endif
                    </ul>
                @endif
                <p>Some records, such as recorded payments, may have to be kept even after your account is deleted. If you think your data was mishandled, you may also contact the National Privacy Commission.</p>
            </x-ui.card>
        </div>
    </section>
@endsection
