---
name: macos-native-ui
description: Build macOS-native inspired Vue 3 + Inertia + Tailwind interfaces with premium minimal polish. Use when designing or restyling UI/UX, layout, visual hierarchy, or page composition in Vue/Inertia apps; when a calm, restrained, product-grade aesthetic is requested; or when the user asks for macOS-like, Apple-esque, or premium minimal UI.
---

# macOS Native UI

## Overview

Produce calm, precise, and confident web UI that borrows macOS design philosophy (not literal mimicry): clarity, hierarchy, restraint, and focus. Favor deliberate typography, soft depth, and quiet motion. Avoid template or generic AI aesthetics.

## Workflow

### 1) Pre-code design decisions (mandatory)

Write a short design spec before code with:
- Primary user action (one clear action)
- Aesthetic direction (default: macOS-native premium minimal)
- Layout strategy (content width, section order, rhythm)
- Signature detail(s) (1-2 memorable details)
- Design tokens (colors, spacing, radius, shadow, typography scale)

### 2) Core design principles

- Use strong typography for hierarchy
- Use generous whitespace with disciplined rhythm
- Use subtle surface layering (soft borders, light shadows)
- Use one restrained accent color
- Use calm, purposeful motion
- Treat dark mode as first-class

### 3) Typography rules

- Prefer system font stack by default for speed and reliability
- If using a custom font: include import instructions and map to Tailwind config
- Enforce a strict type scale: hero, section heading, body, secondary/meta
- Keep readable line length: use max-w-prose or max-w-[60-65ch]

### 4) Color and tokens

- Define CSS variables: --bg, --surface, --surface-2, --border, --text, --muted, --accent, --accent-soft
- Consume tokens via Tailwind arbitrary values
- Keep palette neutral-dominant with one accent
- Avoid loud gradients unless explicitly requested

### 5) Layout and spacing

- Use a consistent container (e.g., max-w-6xl mx-auto px-6)
- Keep strict vertical rhythm (e.g., py-20 md:py-28)
- Avoid repetitive card grids; prefer editorial sections or split layouts
- Use one visual anchor per section (mock UI, metric, illustration, or statement)

### 6) Motion and interaction

- Respect prefers-reduced-motion
- Prefer CSS transitions, subtle entrance reveals, and eased hovers
- Use JS only when needed (e.g., IntersectionObserver)
- Avoid blob animations and heavy animation libraries

### 7) Accessibility (non-negotiable)

- Semantic HTML and correct heading order
- Keyboard navigation everywhere
- Visible focus-visible states
- Real buttons and links
- Adequate contrast
- Forms include labels and error states

### 8) Performance constraints

- Avoid heavy filters everywhere
- Avoid excessive shadows
- Lazy-load non-critical media
- Keep DOM shallow
- Avoid background videos unless requested

## Vue + Inertia structure

- Use an Inertia page component (e.g., resources/js/Pages/Landing.vue)
- Use Vue 3 with <script setup>
- Extract small local components only when needed
- Keep file count minimal; clarity over abstraction

## Tailwind conventions

- Utility-first but readable
- Extract repeated patterns into small components
- Use arbitrary values only for fine polish (tracking, max-w)
- Standardize radius, shadow softness, border weight

## Hard bans

- Generic AI SaaS aesthetics (purple gradients, glow blobs, identical icon cards)
- Overused layouts (three identical cards in a row)
- Fake interactions
- Overengineering or unnecessary libraries

## Output format (required)

1) Design spec (brief)
- Aesthetic direction
- Color tokens
- Typography strategy
- Layout structure
- Signature detail(s)

2) Code
- Complete Vue SFC using <script setup>
- Tailwind utilities
- CSS variables defined or referenced

3) Integration notes
- Where to plug content, routes, images
- Tailwind config changes (only if needed)

## Quality checklist

- Looks premium and intentional at first glance
- Clear hierarchy within 2 seconds
- Calm, uncluttered composition
- Works at 320px without cramped text
- Dark mode feels equally designed
- Accessible focus and semantics
- Clean, readable, not over-engineered code
