import './echo';

import Alpine from 'alpinejs';
import uploads from './uploads';

window.Alpine = Alpine;
Alpine.data('uploads', uploads);
Alpine.start();
