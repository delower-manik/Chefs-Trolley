import type { Config } from "tailwindcss";

// Unified Chefs Trolley design tokens.
// Layout structure (260px sidebar / 72px header) inherited from the v3 prototype.
// Color identity restored to the established Chefs Trolley brand (black surface + red accent).
// "danger" is deliberately a distinct rose from "primary" so destructive actions never
// get lost inside the brand's own accent color.
const config: Config = {
  darkMode: "class",
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      // Every colour is a CSS variable (RGB channels) so one set of class names serves dark AND light mode,
      // and opacity modifiers like bg-danger/10 keep working. Values live in globals.css.
      colors: {
        bg: "rgb(var(--c-bg) / <alpha-value>)",
        surface: "rgb(var(--c-surface) / <alpha-value>)",
        "surface-raised": "rgb(var(--c-surface-raised) / <alpha-value>)",
        "surface-hover": "rgb(var(--c-surface-hover) / <alpha-value>)",
        border: "rgb(var(--c-border) / <alpha-value>)",
        primary: {
          DEFAULT: "rgb(var(--c-primary) / <alpha-value>)",
          hover: "rgb(var(--c-primary-hover) / <alpha-value>)",
          muted: "rgb(var(--c-primary-muted) / <alpha-value>)",
          fg: "rgb(var(--c-primary-fg) / <alpha-value>)", // red used as TEXT: lighter on dark, deeper on light, both >= 4.5:1
        },
        "on-primary": "#FFFFFF", // text on a red / green / rose fill: white in both themes
        strong: "rgb(var(--c-strong) / <alpha-value>)", // headings and values: white on dark, near-black on light
        "text-primary": "rgb(var(--c-text) / <alpha-value>)",
        "text-secondary": "rgb(var(--c-text-secondary) / <alpha-value>)",
        "text-muted": "rgb(var(--c-text-muted) / <alpha-value>)",
        success: "rgb(var(--c-success) / <alpha-value>)",
        warning: "rgb(var(--c-warning) / <alpha-value>)",
        danger: "rgb(var(--c-danger) / <alpha-value>)",
      },
      fontFamily: {
        headline: ["var(--font-jakarta)", "Noto Sans Bengali", "sans-serif"],
        body: ["var(--font-inter)", "Noto Sans Bengali", "sans-serif"],
      },
      spacing: {
        sidebar: "260px",
        header: "72px",
      },
      borderRadius: {
        card: "12px",
      },
      boxShadow: {
        card: "0 4px 16px rgb(0 0 0 / var(--card-shadow-alpha))",
      },
    },
  },
  plugins: [],
};

export default config;
