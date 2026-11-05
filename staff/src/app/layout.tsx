import type { Metadata, Viewport } from "next";
import "./globals.css";
import Providers from "@/components/Providers";

export const metadata: Metadata = {
  title: "Staff · Chefs Trolley", description: "Tables, kitchen and attendance for restaurant staff", manifest: "/manifest.webmanifest",
  robots: { index: false, follow: false },
  icons: { icon: "/icon-192.png", apple: "/icon-192.png" }, appleWebApp: { capable: true, title: "Staff" },
};
export const viewport: Viewport = { themeColor: "#c2410c", width: "device-width", initialScale: 1, viewportFit: "cover" };

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="bn">
      <body><Providers><div className="mx-auto min-h-screen max-w-md px-4 pb-24 pt-4">{children}</div></Providers></body>
    </html>
  );
}
