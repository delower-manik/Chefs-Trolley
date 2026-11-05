"use client";

import { useApp } from "./providers";

export default function Footer() {
  const { site, t } = useApp();
  return (
    <footer className="mt-16 border-t border-line bg-white">
      <div className="wrap grid gap-4 py-8 text-sm text-soft sm:grid-cols-2">
        <div>
          <p className="font-semibold text-ink">{site.siteName}</p>
          {site.tagline && <p>{site.tagline}</p>}
        </div>
        <div>
          <p className="font-semibold text-ink">{t("footer.contact")}</p>
          {site.address && <p>{site.address}</p>}
          {site.contactPhone && <p><a className="hover:text-brand" href={`tel:${site.contactPhone.replace(/[^\d+]/g, "")}`}>{site.contactPhone}</a></p>}
          {site.contactEmail && <p><a className="hover:text-brand" href={`mailto:${site.contactEmail}`}>{site.contactEmail}</a></p>}
        </div>
      </div>
    </footer>
  );
}
