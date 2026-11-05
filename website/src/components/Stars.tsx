export default function Stars({ value }: { value: number }) {
  return <span aria-label={`${value} / 5`} className="text-amber-500">{"★".repeat(Math.round(value))}<span className="text-line">{"★".repeat(5 - Math.round(value))}</span></span>;
}
