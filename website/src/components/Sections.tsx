import L from "./L";
import type { Section } from "@/lib/types";

/** CMS blocks are rendered as plain text and images only: the editor stores no HTML, and nothing here injects markup. */
export default function Sections({ sections }: { sections: Section[] }) {
  return (
    <div className="space-y-10">
      {sections.map((s, i) => {
        const body = s.body ? <p className="whitespace-pre-line text-soft">{s.body}</p> : null;
        if (s.type === "hero")
          return (
            <section key={i} className="relative overflow-hidden rounded-3xl bg-brand/10 px-6 py-14 text-center">
              {s.image && /* eslint-disable-next-line @next/next/no-img-element */ <img src={s.image.url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-30" />}
              <div className="relative">
                {s.title && <h1 className="text-4xl font-bold sm:text-5xl">{s.title}</h1>}
                <div className="mx-auto mt-3 max-w-xl">{body}</div>
                <L href="/menu" className="btn mt-6">Menu</L>
              </div>
            </section>
          );
        if (s.type === "cta")
          return (
            <section key={i} className="rounded-2xl bg-brand px-6 py-8 text-center text-white">
              {s.title && <h2 className="text-2xl font-bold">{s.title}</h2>}
              {s.body && <p className="mt-2 whitespace-pre-line opacity-90">{s.body}</p>}
              <L href="/menu" className="mt-4 inline-flex min-h-[44px] items-center rounded-full bg-white px-5 text-sm font-semibold text-brand">Menu</L>
            </section>
          );
        if (s.type === "image")
          return (
            <figure key={i}>
              {s.title && <h2 className="mb-2 text-2xl font-bold">{s.title}</h2>}
              {s.image && /* eslint-disable-next-line @next/next/no-img-element */ <img src={s.image.url} alt={s.image.alt ?? s.title ?? ""} loading="lazy" className="w-full rounded-2xl object-cover" />}
              {s.body && <figcaption className="mt-2 text-sm text-soft">{s.body}</figcaption>}
            </figure>
          );
        if (s.type === "gallery")
          return (
            <section key={i}>
              {s.title && <h2 className="mb-2 text-2xl font-bold">{s.title}</h2>}
              {body}
              {s.images && s.images.length > 0 && (
                <ul className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                  {s.images.map((im, k) => (
                    // eslint-disable-next-line @next/next/no-img-element
                    <li key={k}><img src={im.url} alt={im.alt ?? ""} loading="lazy" className="aspect-square w-full rounded-xl object-cover" /></li>
                  ))}
                </ul>
              )}
            </section>
          );
        return (
          <section key={i}>
            {s.title && <h2 className="mb-2 text-2xl font-bold">{s.title}</h2>}
            {body}
            {s.type === "menu_highlight" && <L href="/menu" className="btn-ghost mt-3">Menu</L>}
          </section>
        );
      })}
    </div>
  );
}
