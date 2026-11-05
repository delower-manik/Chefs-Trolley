import type { Config } from "tailwindcss";

const config: Config = {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        brand: "rgb(var(--brand) / <alpha-value>)",
        ink: "#1c1917",
        soft: "#78716c",
        paper: "#fafaf9",
        line: "#e7e5e4",
      },
      fontFamily: { sans: ["Inter", "Noto Sans Bengali", "system-ui", "sans-serif"] },
    },
  },
  plugins: [],
};
export default config;
