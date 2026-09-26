import { Injectable, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';

import type { NexoraNotification } from './notification.service';

/** A resolved deep-link target for a notification. */
export interface NotificationNavigationTarget {
  path: string;
  queryParams: { selected: number };
}

/**
 * NotificationNavigationService maps a notification to its related backend
 * record. Every workspace mirrors its selection to `?selected=<id>` in the URL
 * (Assets, Inventory, Requests, Maintenance), so a notification deep-links to
 * the workspace with the related record pre-selected.
 *
 * Only real payload keys are respected (ticket_id, maintenance_request_id,
 * asset_id); a payload missing its id resolves to no target ("No related
 * record available"). Unknown future types never produce a link.
 */
@Injectable({ providedIn: 'root' })
export class NotificationNavigationService {
  private readonly router = inject(Router);

  /** Resolve the target workspace + selection for a notification, or null. */
  resolveTarget(notification: Pick<NexoraNotification, 'type' | 'data'>): NotificationNavigationTarget | null {
    const { type, data } = notification;

    if (type === 'ticket.assigned' || type === 'ticket.status_changed') {
      const id = positiveInt(data.ticket_id);
      if (id !== null) {
        return { path: '/requests', queryParams: { selected: id } };
      }
    }

    if (type === 'maintenance.assigned' || type === 'maintenance.approved' || type === 'maintenance.completed') {
      const id = positiveInt(data.maintenance_request_id);
      if (id !== null) {
        return { path: '/maintenance', queryParams: { selected: id } };
      }
    }

    if (type === 'asset.assigned' || type === 'asset.returned') {
      const id = positiveInt(data.asset_id);
      if (id !== null) {
        return { path: '/assets', queryParams: { selected: id } };
      }
    }

    return null;
  }

  /**
   * Navigate to the related record and return true, or return false when the
   * notification has no resolvable related record.
   */
  openRelated(notification: Pick<NexoraNotification, 'type' | 'data'>): boolean {
    const target = this.resolveTarget(notification);
    if (!target) return false;
    void this.router.navigate([target.path], { queryParams: target.queryParams });
    return true;
  }
}

/** Coerce a payload id (number or numeric string) to a positive integer. */
function positiveInt(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value);
    if (Number.isInteger(parsed) && parsed > 0) {
      return parsed;
    }
  }
  return null;
}

/** Resolve the selected id from the `selected` query param (shared helper). */
export function readSelectedId(route: ActivatedRoute): number | null {
  const raw = route.snapshot.queryParamMap.get('selected');
  if (raw === null || raw === undefined) return null;
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) return null;
  return id;
}