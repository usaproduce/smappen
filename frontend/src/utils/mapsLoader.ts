/**
 * Shared library list for `@react-google-maps/api`'s `useJsApiLoader`.
 *
 * The Google Maps JS SDK can only be loaded ONCE per page session. If two
 * components call `useJsApiLoader` with DIFFERENT options, the loader
 * throws "Loader must not be called again with different options" and the
 * whole page crashes with our ErrorBoundary.
 *
 * Every component that calls `useJsApiLoader` MUST pass this constant
 * — never an inline array, never a subset. AppLayout, SharedProjectPage,
 * EmbedProjectPage, and VendorMapPage all load this same set.
 */
export const GOOGLE_MAPS_LIBRARIES: ('drawing' | 'visualization' | 'geometry' | 'places')[] = [
  'drawing',
  'visualization',
  'geometry',
  'places',
];
