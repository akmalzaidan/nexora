import { Component } from '@angular/core';
import { IonContent, IonHeader, IonIcon, IonTitle, IonToolbar } from '@ionic/angular';

@Component({
  selector: 'app-locations',
  standalone: true,
  imports: [IonHeader, IonToolbar, IonTitle, IonContent, IonIcon],
  template: `
    <ion-header><ion-toolbar><ion-title>Locations</ion-title></ion-toolbar></ion-header>
    <ion-content>
      <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; gap: 16px;">
        <ion-icon name="location-outline" style="font-size: 48px; opacity: 0.5;"></ion-icon>
        <h2 style="margin: 0; font-size: 18px; color: var(--nx-text-secondary);">Locations Module</h2>
        <p style="margin: 0; font-size: 14px;">Coming in a future phase.</p>
      </div>
    </ion-content>
  `,
  styles: [`
    ion-toolbar { --background: var(--nx-surface); --color: var(--nx-text); }
  `],
})
export class LocationsPage {}