"use client";

import { useRef, useState } from "react";
import { useMedia, uploadMedia, deleteMedia } from "@/hooks/use-cms";
import { ApiError } from "@/lib/api/client";
import { Can } from "@/components/ui/can";

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function MediaLibraryPage() {
  const { media, isLoading, error, refresh } = useMedia();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  async function handleFileSelected(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setUploadError(null);
    try {
      await uploadMedia(file);
      refresh();
    } catch (err) {
      setUploadError(err instanceof ApiError ? err.message : "Couldn't upload this file.");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  return (
    <section className="max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-strong">Media Library</h2>
          <p className="text-sm text-text-secondary">Images for your website — JPG, PNG, WEBP, or GIF, up to 5MB.</p>
        </div>
        <Can permission="cms.manage">
          <div>
            <input ref={fileInputRef} type="file" accept="image/*" onChange={handleFileSelected} className="hidden" id="media-upload" />
            <label htmlFor="media-upload" className="btn-primary cursor-pointer">
              {uploading ? "Uploading..." : "+ Upload image"}
            </label>
          </div>
        </Can>
      </div>

      {uploadError && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{uploadError}</div>}

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load the media library.</div>
      ) : isLoading ? (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="aspect-square bg-surface-hover rounded-card animate-pulse" />
          ))}
        </div>
      ) : media.length === 0 ? (
        <div className="card p-10 text-center text-sm text-text-secondary">No images uploaded yet.</div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {media.map((item) => (
            <div key={item.id} className="card overflow-hidden group relative">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={item.url} alt={item.altText ?? item.originalFilename} className="w-full aspect-square object-cover" />
              <div className="p-2">
                <div className="text-xs text-strong truncate">{item.originalFilename}</div>
                <div className="text-[10px] text-text-secondary">{formatSize(item.sizeBytes)}</div>
              </div>
              <Can permission="cms.manage">
                <button
                  onClick={async () => {
                    if (!window.confirm(`Delete "${item.originalFilename}"?`)) return;
                    try {
                      await deleteMedia(item.id);
                      refresh();
                    } catch (err) {
                      window.alert(err instanceof ApiError ? err.message : "Couldn't delete this image.");
                    }
                  }}
                  className="absolute top-2 right-2 bg-danger text-on-primary text-xs rounded px-2 py-1 opacity-0 group-hover:opacity-100 transition-opacity"
                >
                  Delete
                </button>
              </Can>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
