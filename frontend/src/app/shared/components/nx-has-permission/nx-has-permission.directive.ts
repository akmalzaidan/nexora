import { Directive, TemplateRef, ViewContainerRef, inject, input, effect } from '@angular/core';

import { AuthorizationService } from '../../../core/services/authorization.service';

/**
 * Structural directive that renders its host template only when the
 * authenticated user holds the given permission (or holds no permission
 * requirement at all). Central abstraction for action-level visibility:
 *
 *   <button *appNxHasPermission="'manage_assets'">Create asset</button>
 */
@Directive({ selector: '[appNxHasPermission]', standalone: true })
export class NxHasPermissionDirective {
  private readonly authorization = inject(AuthorizationService);
  private readonly templateRef = inject(TemplateRef);
  private readonly viewContainer = inject(ViewContainerRef);

  readonly appNxHasPermission = input<string | null | undefined>(undefined);

  constructor() {
    effect(() => {
      const permission = this.appNxHasPermission();
      const allowed = !permission || this.authorization.hasPermission(permission);
      this.viewContainer.clear();
      if (allowed) {
        this.viewContainer.createEmbeddedView(this.templateRef);
      }
    });
  }
}
