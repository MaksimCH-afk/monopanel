import type { Metadata } from "next";
import { DM_Mono, DM_Sans } from "next/font/google";
import "./globals.css";
import { Header } from "@/components/Header";
import { APP_VERSION } from "@/lib/version";

const dmMono = DM_Mono({
  weight: ["300", "400", "500"],
  subsets: ["latin"],
  variable: "--font-dm-mono",
});

const dmSans = DM_Sans({
  weight: ["400", "500", "600", "700"],
  subsets: ["latin"],
  variable: "--font-dm-sans",
});

export const metadata: Metadata = {
  title: `ImageGen Dashboard v${APP_VERSION}`,
  description: "Compare image-generation models side by side.",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className={`${dmMono.variable} ${dmSans.variable}`}>
      <body className="flex h-screen flex-col overflow-hidden bg-bg text-fg">
        <Header />
        <main className="min-h-0 flex-1 overflow-hidden">{children}</main>
      </body>
    </html>
  );
}
