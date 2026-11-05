export type CmsSectionType = "hero" | "text" | "image" | "menu_highlight" | "gallery" | "cta";

export type CmsSection = {
  type: CmsSectionType;
  [key: string]: unknown;
};

export type CmsPage = {
  id: string;
  slug: string;
  title: string;
  sections: CmsSection[];
  metaTitle: string | null;
  metaDescription: string | null;
  ogMediaId: string | null;
  ogMediaUrl: string | null;
  isPublished: boolean;
  publishedAt: string | null;
  updatedAt: string;
};

export type SaveCmsPagePayload = {
  slug?: string;
  title: string;
  sections?: CmsSection[];
  meta_title?: string;
  meta_description?: string;
  og_media_id?: string;
};

export type MediaItem = {
  id: string;
  url: string;
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  altText: string | null;
  createdAt: string;
};

export type SiteSettings = {
  id: string;
  siteName: string;
  tagline: string | null;
  primaryColor: string;
  logoMediaId: string | null;
  logoUrl: string | null;
  faviconMediaId: string | null;
  faviconUrl: string | null;
  contactPhone: string | null;
  contactEmail: string | null;
  socialFacebook: string | null;
  socialInstagram: string | null;
};

export type SaveSiteSettingsPayload = Partial<{
  site_name: string;
  tagline: string;
  primary_color: string;
  logo_media_id: string;
  favicon_media_id: string;
  contact_phone: string;
  contact_email: string;
  social_facebook: string;
  social_instagram: string;
}>;
