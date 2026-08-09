(() => {
  "use strict";

  const source = document.querySelector(".term-name-wrap input");
  const paired = document.querySelector("#kuka_name_tr");
  if (!source || !paired) return;

  paired.value = source.value;
  const synchronize = () => { source.value = paired.value; };
  paired.addEventListener("input", synchronize);
  paired.form?.addEventListener("submit", synchronize);
})();
