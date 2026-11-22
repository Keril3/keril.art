# keril.art - Personal Landing Page

## Project Overview
This is a personal landing page (visitor card) for keril.art. It's a single-page website with frontend in `index.html` and a PHP endpoint `counter.php` for visitor counting.

## Key Features
- Single-page site without build tools or frameworks
- Windows XP era aesthetic with Frutiger Aero and Tenshi Kawaii themes
- Responsive design that scales with window width
- Visitor counter using PHP-FPM
- GitHub API integration for project listings
- Dark/light theme support
- Accessibility features including keyboard navigation

## Technical Details
- Frontend in `index.html` with inline CSS and JavaScript
- PHP endpoint `counter.php` for visitor counting
- Uses Monocraft font for UI elements
- Responsive design with zoom scaling based on window width
- Supports both Russian and English languages
- Includes cookie banner and Cloudflare Turnstile protection

## How to Run Locally
1. The site can be opened directly in a browser using `index.html`
2. For visitor counter functionality, PHP-FPM is required
3. A local Python HTTP server can be started with: `python -m http.server 8000 --directory landing_page`

## Deployment
The website is hosted on a VPS with:
- Ubuntu 26.04
- Nginx 1.28
- PHP 8.5
- SSL certificate from Let's Encrypt

## Files
- `index.html` - Main page content
- `counter.php` - Visitor counter backend
- `404.html` - Custom 404 error page
- `robots.txt` - Search engine directives
- `og-image.jpg` - Open Graph preview image
- `favicon.ico` - Browser favicon
- `apple-touch-icon.png` - Mobile touch icon

## Documentation
The full documentation is in CLAUDE.md file.