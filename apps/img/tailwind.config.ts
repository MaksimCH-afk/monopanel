import type { Config } from "tailwindcss";

const config: Config = {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // Neutral, image-friendly background scale
        bg: {
          DEFAULT: "#0a0a0a",
          raised: "#121212",
          panel: "#0e0e0e",
          border: "#1f1f1f",
          hover: "#191919",
        },
        fg: {
          DEFAULT: "#e8e8e8",
          muted: "#8a8a8a",
          faint: "#5a5a5a",
        },
        // Accent roles from the spec
        action: "#34d399", // green — actions, activity, Generate
        param: "#a78bfa", // purple — generation parameters
      },
      fontFamily: {
        mono: ["var(--font-dm-mono)", "ui-monospace", "monospace"],
        sans: ["var(--font-dm-sans)", "ui-sans-serif", "system-ui", "sans-serif"],
      },
      keyframes: {
        "fade-in-up": {
          "0%": { opacity: "0", transform: "translateY(8px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
        "progress-indeterminate": {
          "0%": { transform: "translateX(-100%)" },
          "100%": { transform: "translateX(400%)" },
        },
        "pulse-dot": {
          "0%, 100%": { opacity: "1" },
          "50%": { opacity: "0.3" },
        },
      },
      animation: {
        "fade-in-up": "fade-in-up 0.35s ease-out both",
        "progress-indeterminate":
          "progress-indeterminate 1.1s ease-in-out infinite",
        "pulse-dot": "pulse-dot 1.4s ease-in-out infinite",
      },
    },
  },
  plugins: [],
};

export default config;
