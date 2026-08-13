<script>
/**
 * As mesmas regras que o servidor aplica aos semestres, só que no navegador: a
 * mensagem aparece dentro do próprio formulário — no modal, sem fechá-lo — em
 * vez de voltar como aviso no topo da página, com o que foi digitado perdido.
 *
 * Vale para qualquer formulário que tenha os quatro campos e uma caixa
 * .erro-semestres. O servidor continua conferindo: isto é conveniência, não
 * garantia.
 */
(function () {
  document.querySelectorAll('form [name="sem1_inicio"]').forEach(function (campo) {
    var form  = campo.form;
    var caixa = form.querySelector('.erro-semestres');
    if (!caixa) { return; }

    function valor(nome) {
      var el = form.querySelector('[name="' + nome + '"]');
      return el ? el.value : '';
    }

    form.addEventListener('submit', function (ev) {
      var s1i = valor('sem1_inicio'), s1f = valor('sem1_fim'),
          s2i = valor('sem2_inicio'), s2f = valor('sem2_fim');
      var erro = '';

      if (!s1i || !s1f || !s2i || !s2f) {
        erro = 'Informe as datas de início e fim dos dois semestres letivos.';
      } else if (s1f < s1i) {
        erro = 'No 1º semestre, o fim está antes do início.';
      } else if (s2f < s2i) {
        erro = 'No 2º semestre, o fim está antes do início.';
      } else if (s2i < s1f) {
        erro = 'O 2º semestre começa antes de o 1º terminar.';
      }

      if (erro === '') {
        caixa.classList.add('d-none');
        return;
      }
      ev.preventDefault();
      caixa.textContent = erro;
      caixa.classList.remove('d-none');
      caixa.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  });
})();
</script>
