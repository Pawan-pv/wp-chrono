import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './index.css';

const root = document.getElementById('wp-chrono-root');

if (root) {
  createRoot(root).render(
    <StrictMode>
      <App root={root} />
    </StrictMode>,
  );
}
