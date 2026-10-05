import { useEffect, useState } from 'react';

function read(): 'light' | 'dark' {
  return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

/**
 * The app's theme as the <html data-theme> attribute says it. Data colours that cannot be CSS
 * variables (the map ramps of the week strip) follow it: on the dark base the ramp is reversed.
 */
export function useThemeName(): 'light' | 'dark' {
  const [theme, setTheme] = useState<'light' | 'dark'>(read);
  useEffect(() => {
    const observer = new MutationObserver(() => setTheme(read()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    setTheme(read());
    return () => observer.disconnect();
  }, []);
  return theme;
}
