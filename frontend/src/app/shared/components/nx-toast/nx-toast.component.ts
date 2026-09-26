import { Injectable, Component, inject, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonIcon } from '@ionic/angular';

export interface NxToast {
  id: number;
  title: string;
  message: string;
  type: 'success' | 'warning' | 'danger' | 'info';
  icon: string;
  createdAt: number;
  duration: number;
}

/**
 * NxToastService — root-provided notification service.
 *
 * Owns the toast state so any page or service can raise a notification
 * without depending on where the container is rendered. The container
 * (`app-nx-toast-container`) simply renders whatever the service holds.
 */
@Injectable({ providedIn: 'root' })
export class NxToastService {
  readonly toasts = signal<NxToast[]>([]);

  private counter = 0;

  show(options: { title?: string; message: string; type?: 'success' | 'warning' | 'danger' | 'info'; icon?: string; duration?: number }): number {
    const id = ++this.counter;
    const type = options.type ?? 'info';
    const toast: NxToast = {
      id,
      title: options.title ?? '',
      message: options.message,
      type,
      icon: options.icon ?? this.defaultIcon(type),
      createdAt: Date.now(),
      duration: options.duration ?? 4000,
    };
    this.toasts.update(t => [...t, toast]);
    if (toast.duration > 0) {
      setTimeout(() => this.remove(id), toast.duration);
    }
    return id;
  }

  remove(id: number): void {
    this.toasts.update(t => t.filter(item => item.id !== id));
  }

  success(title: string, message: string, duration?: number): number {
    return this.show({ title, message, type: 'success', duration });
  }

  warning(title: string, message: string, duration?: number): number {
    return this.show({ title, message, type: 'warning', duration });
  }

  danger(title: string, message: string, duration?: number): number {
    return this.show({ title, message, type: 'danger', duration });
  }

  info(title: string, message: string, duration?: number): number {
    return this.show({ title, message, type: 'info', duration });
  }

  private defaultIcon(type: 'success' | 'warning' | 'danger' | 'info'): string {
    switch (type) {
      case 'success': return 'checkmark-circle';
      case 'warning': return 'alert-circle';
      case 'danger': return 'alert-circle';
      case 'info': return 'information-circle';
    }
  }
}

/**
 * NxToastContainerComponent — renders the toasts owned by NxToastService.
 *
 * Mount once per application (the app shell hosts it). Visibility is
 * driven entirely by the service, so it can be placed anywhere.
 */
@Component({
  selector: 'app-nx-toast-container',
  standalone: true,
  imports: [CommonModule, IonIcon],
  template: `
    <div class="nx-toast-container">
      @for (toast of toasts(); track toast.id) {
        <div
          class="nx-toast"
          [class.nx-toast-success]="toast.type === 'success'"
          [class.nx-toast-warning]="toast.type === 'warning'"
          [class.nx-toast-danger]="toast.type === 'danger'"
          [class.nx-toast-info]="toast.type === 'info'"
          role="status"
        >
          @if (toast.icon) {
            <div class="nx-toast-icon">
              <ion-icon [name]="toast.icon"></ion-icon>
            </div>
          }
          <div class="nx-toast-content">
            @if (toast.title) {
              <p class="nx-toast-title">{{ toast.title }}</p>
            }
            <p class="nx-toast-message">{{ toast.message }}</p>
          </div>
          <button class="nx-toast-close" (click)="remove(toast.id)" aria-label="Dismiss notification">
            <ion-icon name="close-circle"></ion-icon>
          </button>
        </div>
      }
    </div>
  `,
  styles: [`
    .nx-toast-container {
      position: fixed; bottom: 24px; right: 24px; z-index: 500;
      display: flex; flex-direction: column; gap: 8px; max-width: 400px;
      @media (max-width: 767px) { left: 16px; right: 16px; bottom: 16px; max-width: none; }
    }
    .nx-toast {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 12px 16px; background: var(--nx-surface-elevated);
      border: 1px solid var(--nx-border); border-radius: 10px;
      box-shadow: 0 10px 15px rgba(0,0,0,0.3);
      animation: nx-toast-in 200ms cubic-bezier(0.4,0,0.2,1);
      @keyframes nx-toast-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    }
    .nx-toast-success { border-left: 3px solid var(--nx-success); }
    .nx-toast-warning { border-left: 3px solid var(--nx-warning); }
    .nx-toast-danger { border-left: 3px solid var(--nx-danger); }
    .nx-toast-info { border-left: 3px solid var(--nx-info); }
    .nx-toast-icon { flex-shrink: 0; font-size: 18px; ion-icon { font-size: 18px; } }
    .nx-toast-success .nx-toast-icon ion-icon { color: var(--nx-success); }
    .nx-toast-warning .nx-toast-icon ion-icon { color: var(--nx-warning); }
    .nx-toast-danger .nx-toast-icon ion-icon { color: var(--nx-danger); }
    .nx-toast-info .nx-toast-icon ion-icon { color: var(--nx-info); }
    .nx-toast-content { flex: 1; min-width: 0; }
    .nx-toast-title { font-size: 14px; font-weight: 500; color: var(--nx-text); margin: 0 0 2px; }
    .nx-toast-message { font-size: 13px; color: var(--nx-text-secondary); line-height: 1.625; margin: 0; }
    .nx-toast-close {
      background: none; border: none; cursor: pointer;
      color: var(--nx-text-muted); padding: 4px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 4px; flex-shrink: 0; font-size: 16px;
      transition: color 120ms cubic-bezier(0.4,0,0.2,1);
      &:hover { color: var(--nx-text); }
    }
  `],
})
export class NxToastContainerComponent {
  private readonly service = inject(NxToastService);

  /** Toasts managed by NxToastService. */
  readonly toasts = computed(() => this.service.toasts());

  remove(id: number): void {
    this.service.remove(id);
  }
}