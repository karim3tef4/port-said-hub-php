/* نفس مفتاح الوضع الليلي في الموقع (ps_theme) عشان اللوحة تتبع اختيارك */
(function () {
  var root = document.documentElement;
  try { if (localStorage.getItem('ps_theme') === 'dark') root.classList.add('dark'); } catch (e) {}
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('theme');
    if (!b) return;
    b.addEventListener('click', function () {
      root.classList.add('theme-anim');
      var dark = root.classList.toggle('dark');
      try { localStorage.setItem('ps_theme', dark ? 'dark' : 'light'); } catch (e) {}
      setTimeout(function () { root.classList.remove('theme-anim'); }, 600);
    });
  });
})();
