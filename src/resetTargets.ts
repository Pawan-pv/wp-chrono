import {
  Eraser,
  ImageOff,
  Palette,
  ShoppingCart,
  SlidersHorizontal,
  Table2,
  Unlink,
  Unplug,
  type LucideIcon,
} from 'lucide-react';
import type { ResetTarget } from '@/types';

export interface TargetMeta {
  id: ResetTarget;
  title: string;
  description: string;
  icon: LucideIcon;
  /** Noun for the item count badge. */
  unit: string;
  /** Extra search terms for the command palette. */
  keywords: string;
}

export const RESET_TARGETS: TargetMeta[] = [
  {
    id: 'transients',
    title: 'Purge Transients',
    description: 'Cached values in wp_options. Safe to clear; plugins rebuild them on demand.',
    icon: Eraser,
    unit: 'rows',
    keywords: 'cache transient purge clear',
  },
  {
    id: 'woocommerce',
    title: 'Reset WooCommerce',
    description: 'Products, orders, coupons, sessions and lookup tables. Store settings are kept.',
    icon: ShoppingCart,
    unit: 'items',
    keywords: 'woocommerce shop store products orders coupons',
  },
  {
    id: 'uploads',
    title: 'Clean Uploads',
    description: 'Every file in wp-content/uploads. Protection files and WP Chrono storage are kept.',
    icon: ImageOff,
    unit: 'files',
    keywords: 'uploads media files images delete',
  },
  {
    id: 'inactive_plugins',
    title: 'Delete Inactive Plugins',
    description: 'Removes plugins that are installed but not active, running their uninstallers.',
    icon: Unplug,
    unit: 'plugins',
    keywords: 'plugins inactive delete remove',
  },
  {
    id: 'inactive_themes',
    title: 'Delete Inactive Themes',
    description: 'Removes every theme except the active one and its parent.',
    icon: Palette,
    unit: 'themes',
    keywords: 'themes inactive delete remove',
  },
  {
    id: 'custom_tables',
    title: 'Custom Tables',
    description: 'Non-core tables created by plugins. Empty them or drop them entirely.',
    icon: Table2,
    unit: 'rows',
    keywords: 'custom tables drop truncate database',
  },
  {
    id: 'orphaned_meta',
    title: 'Purge Orphaned Meta',
    description: 'postmeta, commentmeta and termmeta rows whose parent no longer exists.',
    icon: Unlink,
    unit: 'rows',
    keywords: 'orphaned postmeta meta cleanup database',
  },
  {
    id: 'options',
    title: 'Reset Options',
    description: 'Restores wp_options defaults. Keeps URLs, theme, plugins, roles and salts.',
    icon: SlidersHorizontal,
    unit: 'options',
    keywords: 'options settings defaults wp_options',
  },
];

export const targetMeta = (id: ResetTarget) => RESET_TARGETS.find((t) => t.id === id)!;
