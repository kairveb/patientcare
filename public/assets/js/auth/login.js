(() => {
  const form = document.getElementById("login-form");
  const error = document.getElementById("login-error");

  form?.addEventListener("submit", (event) => {
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
      return;
    }

    const values = new FormData(form);
    const passwordValue = String(values.get("password") || "");
    if (!passwordValue.trim()) {
      event.preventDefault();
      if (error) {
        error.textContent = "Enter a password to continue.";
        error.hidden = false;
      }
      return;
    }

    if (error) {
      error.hidden = true;
      error.textContent = "";
    }
  });
})();
