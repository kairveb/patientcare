import { test, expect } from '@playwright/test';
import path from 'node:path';

test('each auth password toggle controls only its own input', async ({ page }) => {
  await page.setContent(`
    <div class="password-field">
      <input id="login-password" type="password">
      <button type="button" data-password-toggle><i></i></button>
    </div>
    <div class="password-field">
      <input id="password" type="password">
      <button type="button" data-password-toggle><i></i></button>
    </div>
    <div class="password-field">
      <input id="password_confirmation" type="password">
      <button type="button" data-password-toggle><i></i></button>
    </div>
    <form id="login-form"><p id="login-error" hidden></p></form>
  `);
  await page.addScriptTag({ path: path.resolve('public/assets/js/auth/login.js') });

  const passwords = page.locator('.password-field input');
  const toggles = page.locator('[data-password-toggle]');

  await toggles.nth(1).click();
  await expect(passwords.nth(0)).toHaveAttribute('type', 'password');
  await expect(passwords.nth(1)).toHaveAttribute('type', 'text');
  await expect(passwords.nth(2)).toHaveAttribute('type', 'password');
  await expect(toggles.nth(1)).toHaveAttribute('aria-pressed', 'true');

  await toggles.nth(2).click();
  await expect(passwords.nth(1)).toHaveAttribute('type', 'text');
  await expect(passwords.nth(2)).toHaveAttribute('type', 'text');

  await toggles.nth(0).click();
  await expect(passwords.nth(0)).toHaveAttribute('type', 'text');
});
