<?php

declare(strict_types=1);

namespace Database\Seeders;

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Database\Seeder;

/**
 * Defines the whole plan catalog: one feature of EVERY type, then three
 * packages (free / pro / enterprise) that attach those features at
 * different values.
 *
 * Run once after migrating:  php artisan db:seed --class=CatalogSeeder
 *
 * Every other example in this folder assumes these slugs exist.
 *
 * The fluent builders are idempotent via createOrUpdate() — re-running the
 * seeder updates in place instead of throwing on a duplicate slug, so it is
 * safe in deploy pipelines.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFeatures();
        $this->seedPackages();
    }

    /**
     * One feature per FeatureType so the rest of the cookbook can show each
     * consumption path end to end.
     */
    private function seedFeatures(): void
    {
        // BOOLEAN — a pure on/off gate. No counter, never consumed.
        // The package decides on/off by attaching value 'true' or 'false'.
        Tashil::feature('sso')
            ->name('Single Sign-On')
            ->description('SAML / OIDC single sign-on')
            ->boolean()
            ->create();

        // LIMIT (resets) — a capped counter that auto-resets every month.
        // Classic "10,000 API calls / month" metering with a hard ceiling.
        Tashil::feature('api-calls')
            ->name('API Calls')
            ->description('Monthly API request quota')
            ->limit()
            ->resetMonthly()
            ->create();

        // LIMIT (never resets) — a capped counter with NO reset cadence.
        // Good for "max 10 team seats": the cap travels with the plan, the
        // count only changes when seats are added/removed, never on a clock.
        Tashil::feature('team-seats')
            ->name('Team Seats')
            ->description('Maximum active members')
            ->limit()
            ->resetPeriod(\Foysal50x\Tashil\Enums\ResetPeriod::Never)
            ->create();

        // CONSUMABLE — a counter with NO built-in cap. Increments always
        // succeed; you enforce balance yourself (or top it up). Resets monthly
        // here so the allowance refills each cycle.
        Tashil::feature('email-credits')
            ->name('Email Credits')
            ->description('Transactional emails included per cycle')
            ->consumable()
            ->resetMonthly()
            ->create();

        // METERED — pay-as-you-go. Each consume charges units × unit_price
        // through your MeteredBilling implementation BEFORE the counter moves.
        // No limit_value; the package's value holds the per-unit price.
        Tashil::feature('ai-tokens')
            ->name('AI Tokens')
            ->description('LLM tokens billed per use')
            ->metered()
            ->create();

        // ENUM — a one-of-a-set gate. The snapshot value is the chosen option
        // for that plan (e.g. which export format / support tier is unlocked).
        Tashil::feature('export-format')
            ->name('Export Format')
            ->description('Highest export format the plan unlocks')
            ->type(FeatureType::Enum)
            ->create();
    }

    /**
     * Three packages spanning the activation models:
     *  - free:       requires_payment = false  → Active immediately
     *  - pro:        priced + trial            → OnTrial, then billed at convert
     *  - enterprise: priced, no trial          → Pending until invoice paid
     */
    private function seedPackages(): void
    {
        $sso          = Feature::where('slug', 'sso')->firstOrFail();
        $apiCalls     = Feature::where('slug', 'api-calls')->firstOrFail();
        $seats        = Feature::where('slug', 'team-seats')->firstOrFail();
        $emailCredits = Feature::where('slug', 'email-credits')->firstOrFail();
        $aiTokens     = Feature::where('slug', 'ai-tokens')->firstOrFail();
        $exportFormat = Feature::where('slug', 'export-format')->firstOrFail();

        // FREE — activates instantly (requiresPayment(false)). No invoice, no
        // gateway. The lowest tier of the catalog.
        Tashil::package('free')
            ->name('Free')
            ->description('For trying things out')
            ->price(0)
            ->monthly()
            ->requiresPayment(false)          // skip the pay-to-activate gate
            ->feature($sso, value: 'false')   // SSO locked
            ->feature($apiCalls, value: '1000')
            ->feature($seats, value: '2')
            ->feature($exportFormat, value: 'csv')
            ->createOrUpdate();

        // PRO — $29/mo with a 14-day trial. Subscribing with a trial grants
        // access now and bills nothing until convertTrial(). All feature types
        // are present so the consumption examples have something to hit.
        Tashil::package('pro')
            ->name('Pro')
            ->description('For growing teams')
            ->price(29)
            ->monthly()
            ->trialDays(14)
            // requiresPayment defaults to the install setting
            // (tashil.billing.activate_on_payment, default true) — so without
            // a trial this plan would subscribe as Pending until paid.
            ->feature($sso, value: 'true')               // SSO unlocked
            ->feature($apiCalls, value: '50000')         // 50k calls / month
            ->feature($seats, value: '10')               // up to 10 seats
            ->feature($emailCredits, value: '5000')      // 5k emails / cycle
            ->feature($aiTokens, value: '0.0002')        // $0.0002 per token
            ->feature($exportFormat, value: 'pdf')       // pdf export unlocked
            ->createOrUpdate();

        // ENTERPRISE — $299/mo, no trial, pay-to-activate. Subscribing creates
        // a Pending subscription + an initial invoice; access starts when the
        // invoice is paid (see 02-paid-invoice).
        Tashil::package('enterprise')
            ->name('Enterprise')
            ->description('For organizations at scale')
            ->price(299)
            ->monthly()
            ->featured()
            ->feature($sso, value: 'true')
            ->feature($apiCalls, value: '1000000')
            ->feature($seats, value: '500')
            ->feature($emailCredits, value: '100000')
            ->feature($aiTokens, value: '0.0001')        // cheaper per-unit rate
            ->feature($exportFormat, value: 'json')
            ->createOrUpdate();
    }
}
