<?php
/**
 * Google Places content storage grant — Carafe Vendor Network Spec v3
 * preamble + §10 guardrail 1.
 *
 * The standard Google Maps Platform ToS limits persistent storage of
 * Places content to Place IDs only. Carafe operates under a *written
 * exception* from Google permitting full Places content storage. This
 * file is the auditable record of that exception, surfaced in the
 * admin UI (§8 grant banner) so the storage basis is visible.
 *
 * If the grant is ever scoped or revoked, set `places_storage_allowed`
 * to false. The system falls back to Place-ID-only behavior with no
 * refactor — every storage decision in the pipeline reads through this
 * flag.
 *
 * KEEP THIS FILE OUT OF PUBLIC REPOS IF THE GRANT TEXT IS CONFIDENTIAL.
 * The metadata below is a stub — replace with the real grant reference,
 * date, scope, and expiry when filing the written grant on disk.
 */

return [
    // Hard switch read by PlacesClient::isStorageAllowed(). When false,
    // sweep masks are forced to `places.id` only, Place Details calls
    // are refused, and the enrich pipeline becomes a no-op.
    'places_storage_allowed' => true,

    // Written grant metadata — fill in when the real grant is in hand.
    'grant_ref'   => 'TBD-FILL-IN-FROM-WRITTEN-GRANT',
    'granted_at'  => null,      // ISO 8601 date Google issued the exception
    'expires_at'  => null,      // ISO 8601 date the exception lapses, or null for indefinite
    'scope'       => [
        // List the field families the grant covers. The pipeline still
        // honors the storage flag globally, but UI surfaces scope so
        // operators can see what's permitted.
        'identity'      => true,   // id, displayName, address, location
        'contact'       => true,   // phone, website
        'atmosphere'    => true,   // rating, reviews, hours, attributes
        'reviews'       => true,   // Places review payloads (up to 5)
        'photos'        => true,   // photo references (not binaries)
        'raw_payload'   => true,   // verbatim JSON in vendor_google_details.raw_payload_json
    ],

    // Optional contact for verification. Surfaces in the admin grant banner.
    'verification' => [
        'contact_name'  => null,
        'contact_email' => null,
        'contract_url'  => null,
    ],
];
