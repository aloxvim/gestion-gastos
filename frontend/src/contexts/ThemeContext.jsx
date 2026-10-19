import { createContext, useContext, useEffect, useState } from 'react';

const ThemeContext = createContext(null);

const STORAGE_KEY = 'theme';
const VALID_THEMES = ['light', 'dark', 'system'];

const getStoredTheme = () => {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return VALID_THEMES.includes(stored) ? stored : 'system';
  } catch {
    return 'system';
  }
};

const systemPrefersDark = () =>
  window.matchMedia('(prefers-color-scheme: dark)').matches;

export const ThemeProvider = ({ children }) => {
  // Preferencia elegida por el usuario: 'light', 'dark' o 'system'
  const [theme, setTheme] = useState(getStoredTheme);
  const [isDark, setIsDark] = useState(
    () => theme === 'dark' || (theme === 'system' && systemPrefersDark())
  );

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const apply = () => {
      const dark = theme === 'dark' || (theme === 'system' && media.matches);
      document.documentElement.classList.toggle('dark', dark);
      setIsDark(dark);
    };

    apply();

    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch {
      // Sin acceso a localStorage: el tema se aplica igualmente en esta sesión
    }

    // Seguir los cambios del sistema operativo si la preferencia es 'system'
    if (theme === 'system') {
      media.addEventListener('change', apply);
      return () => media.removeEventListener('change', apply);
    }
  }, [theme]);

  return (
    <ThemeContext.Provider value={{ theme, setTheme, isDark }}>
      {children}
    </ThemeContext.Provider>
  );
};

export const useTheme = () => {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error('useTheme debe usarse dentro de ThemeProvider');
  }
  return context;
};
