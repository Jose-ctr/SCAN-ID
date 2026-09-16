import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";

export default defineConfig({
    base: "/SCAN-ID/",

    plugins: [
        react(),

        VitePWA({
            registerType: "autoUpdate",
            injectRegister: "auto",

            includeAssets: [
                "favicon.ico",
                "robots.txt",
                "sitemap.xml",
                "icons/icon-192.png",
                "icons/icon-512.png"
            ],

            manifest: {
                name: "SCAN-ID — Lost ID Recovery Network Kenya",
                short_name: "SCAN-ID",
                description:
                    "Lost ID Recovery Network Kenya. Scan found IDs, notify owners via SMS, secure M-Pesa recovery fee, and coordinate safe handover.",
                lang: "en-KE",
                start_url: "/SCAN-ID/",
                scope: "/SCAN-ID/",
                display: "standalone",
                orientation: "portrait",
                theme_color: "#0B0F0D",
                background_color: "#0B0F0D",
                categories: [
                    "security",
                    "utilities"
                ],

                icons: [
                    {
                        src: "/SCAN-ID/icons/icon-192.png",
                        sizes: "192x192",
                        type: "image/png",
                        purpose: "any maskable"
                    },
                    {
                        src: "/SCAN-ID/icons/icon-512.png",
                        sizes: "512x512",
                        type: "image/png",
                        purpose: "any maskable"
                    }
                ]
            },

            workbox: {
                cleanupOutdatedCaches: true,

                globPatterns: [
                    "**/*.{js,css,html,ico,png,svg,webp,woff2}"
                ],

                navigateFallback: "/SCAN-ID/index.html"
            },

            devOptions: {
                enabled: true
            }
        })
    ]
});
