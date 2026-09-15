import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";

export default defineConfig({
    plugins: [
        react(),

        VitePWA({
            registerType: "autoUpdate",
            injectRegister: "auto",

            includeAssets: [
                "favicon.ico",
                "robots.txt",
                "icons/icon-192.png",
                "icons/icon-512.png"
            ],

            manifest: {
                name: "SCAN-ID — Lost ID Recovery Network Kenya",
                short_name: "SCAN-ID",
                description:
                    "Lost ID Recovery Network Kenya. Scan found IDs, notify owners via SMS, secure M-Pesa recovery fee, and coordinate safe handover.",
                theme_color: "#0B0F0D",
                background_color: "#0B0F0D",
                display: "standalone",
                orientation: "portrait",
                scope: "/",
                start_url: "/",
                categories: [
                    "security",
                    "utilities"
                ],

                icons: [
                    {
                        src: "icons/icon-192.png",
                        sizes: "192x192",
                        type: "image/png",
                        purpose: "any"
                    },
                    {
                        src: "icons/icon-512.png",
                        sizes: "512x512",
                        type: "image/png",
                        purpose: "any"
                    }
                ]
            },

            workbox: {
                cleanupOutdatedCaches: true,
                globPatterns: [
                    "**/*.{js,css,html,ico,png,svg,webp,woff2}"
                ],
                navigateFallback: "/index.html"
            },

            devOptions: {
                enabled: true
            }
        })
    ]
});
