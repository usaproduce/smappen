<?php
/**
 * Report templates — what sections each preset includes + how they're
 * branded. ReportController accepts `?template=executive` etc. and renders
 * only the listed sections. Adding a new template means: pick an ID, list
 * sections, optionally set a logo override.
 *
 * Section IDs map to the renderers in PdfReportService (cover, summary,
 * demographics, segments, competitors, pois, ai_score, methodology).
 */
return [
    'executive' => [
        'label' => 'Executive summary',
        'description' => 'Cover + high-level metrics + AI verdict. 2-3 pages.',
        'sections' => ['cover', 'summary', 'ai_score'],
        'page_size' => 'Letter',
    ],
    'site_selection' => [
        'label' => 'Site selection',
        'description' => 'Full report for picking between candidates — demographics, competition, segment mix, AI score.',
        'sections' => ['cover', 'summary', 'demographics', 'segments', 'competitors', 'ai_score', 'methodology'],
        'page_size' => 'Letter',
    ],
    'franchise_pitch' => [
        'label' => 'Franchise pitch',
        'description' => 'Customer-facing — covers reach + affluence + photos + a one-paragraph narrative.',
        'sections' => ['cover', 'summary', 'demographics', 'pois', 'ai_score'],
        'page_size' => 'Letter',
        'tone' => 'sales',
    ],
    'demographics_only' => [
        'label' => 'Demographics deep-dive',
        'description' => 'Pure data — full census tables, age/income/housing, trends.',
        'sections' => ['cover', 'demographics', 'segments', 'methodology'],
        'page_size' => 'Letter',
    ],
];
