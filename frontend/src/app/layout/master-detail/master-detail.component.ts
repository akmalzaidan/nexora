import { Component, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonIcon } from '@ionic/angular';

/**
 * MasterDetailLayout provides the reusable master-detail workspace pattern
 * for future modules (Assets, Inventory, Requests, Maintenance, Users).
 *
 * Usage:
 *   <app-nx-master-detail>
 *     <ng-template nxMasterContent>
 *       <!-- list items -->
 *     </ng-template>
 *     <ng-template nxDetailContent>
 *       <!-- detail panel -->
 *     </ng-template>
 *   </app-nx-master-detail>
 */
@Component({
  selector: 'app-nx-master-detail',
  standalone: true,
  imports: [CommonModule, IonIcon],
  templateUrl: './master-detail.component.html',
  styleUrl: './master-detail.component.scss',
})
export class MasterDetailComponent {
  /** Whether the detail panel is visible. */
  detailVisible = false;

  /** Currently selected item identifier (for future binding). */
  selectedId = signal('');

  /** Emitted when the detail panel is closed (mobile back, close button). */
  readonly detailClosed = output<void>();

  /** Toggle the detail panel. */
  toggleDetail(): void {
    this.detailVisible = !this.detailVisible;
  }

  /** Close the detail panel and clear the selected identifier. */
  closeDetail(): void {
    this.detailVisible = false;
    this.selectedId.set('');
    this.detailClosed.emit();
  }

  /** Select an item and show detail. */
  select(id: string): void {
    this.selectedId.set(id);
    this.detailVisible = true;
  }
}
