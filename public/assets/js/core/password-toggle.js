(() => {
  document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
    const password = toggle.closest(".password-field")?.querySelector("input[type='password'], input[type='text']");
    if (!password) return;

    toggle.addEventListener("click", () => {
      const show = password.type === "password";
      password.type = show ? "text" : "password";
      toggle.setAttribute("aria-pressed", String(show));
      toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
      const icon = toggle.querySelector("i");
      if (icon) icon.className = `ph ${show ? "ph-eye-slash" : "ph-eye"}`;
    });
  });
})();
