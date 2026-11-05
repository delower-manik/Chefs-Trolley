import { notFound } from "next/navigation";

/** Any unknown address under /en or /bn renders the styled 404 inside the site layout. */
export default function CatchAll() { notFound(); }
