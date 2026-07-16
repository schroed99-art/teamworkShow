<?php
/**
 * Shared dashboard branding partials — single source so every page (overview,
 * admin, einstellungen …) shows the SAME role-area badge and company signature.
 *
 * - tw_brand_css():  a <style> block; drop it right after each page's </style>.
 * - tw_area_badge(): the "which area am I in" pill for the header (Admin vs Kunde).
 * - tw_brandby():    the fixed "powered by IT&Media Solution" signature (bottom-right).
 *
 * Colours are hard-coded here (not CSS vars) so the partial works regardless of
 * what each page defines. "Solution" uses the IT&Media brand red #e2001a.
 */

function tw_brand_css(): string
{
    return <<<'CSS'
<style>
  .tw-area { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    border-radius:999px; padding:4px 11px; white-space:nowrap; line-height:1; }
  .tw-area::before { content:"\2022"; font-size:9px; vertical-align:middle; margin-right:6px; }
  .tw-area-admin { color:#fecdd6; background:rgba(210,26,85,.16); border:1px solid rgba(210,26,85,.55); }
  .tw-area-kunde { color:#bae6fd; background:rgba(56,189,248,.14); border:1px solid rgba(56,189,248,.5); }
  .tw-brandby { position:fixed; right:30px; bottom:14px; z-index:2; font-size:12.5px; color:#94a3b8;
    letter-spacing:.02em; pointer-events:none; }
  .tw-brandby b { font-weight:600; color:#f1f5f9; }
  .tw-brandby .tw-sol { color:#e2001a; font-weight:700; }
</style>
CSS;
}

function tw_area_badge(bool $isKunde): string
{
    $cls   = $isKunde ? 'tw-area-kunde' : 'tw-area-admin';
    $label = $isKunde ? 'Kundenbereich' : 'Teamwork-Adminbereich';
    return '<span class="tw-area ' . $cls . '">' . $label . '</span>';
}

function tw_brandby(): string
{
    return '<div class="tw-brandby">powered by <b>IT&amp;Media <span class="tw-sol">Solution</span></b></div>';
}
