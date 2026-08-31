<script>
/**
 * As mesmas regras que o servidor aplica aos bimestres, só que no navegador: a
 * mensagem aparece dentro do próprio formulário — no modal, sem fechá-lo — em
 * vez de voltar como aviso no topo da página, com o que foi digitado perdido.
 *
 * Vale para qualquer formulário que tenha os oito campos e uma caixa
 * .erro-periodos. O servidor continua conferindo: isto é conveniência, não
 * garantia. Os rótulos saem dos próprios <label>, então a mensagem já sai no
 * vocabulário do regime do curso — "2º bimestre" ou "1º sem. · 2º bim.".
 */
(function () {
  document.querySelectorAll('form [name="bim1_inicio"]').forEach(function (campo) {
    var form  = campo.form;
    var caixa = form.querySelector('.erro-periodos');
    if (!caixa) { return; }

    function valor(nome) {
      var el = form.querySelector('[name="' + nome + '"]');
      return el ? el.value : '';
    }
    function rotulo(n) {
      var el = form.querySelector('[data-rotulo="bim' + n + '_inicio"]');
      return el ? el.textContent.replace(/\s*—\s*início\s*$/, '') : n + 'º bimestre';
    }
    // O <input type="date"> guarda em ISO, mas a mensagem é para ler: a mesma
    // dd/mm/aaaa que o dataBr() do servidor escreve na mensagem gêmea desta.
    function brasileira(iso) {
      return iso.split('-').reverse().join('/');
    }
    // Na criação o ano é um campo que ainda muda; na tela de dados ele é fixo e
    // vem no data-ano do formulário, porque lá o campo aparece desabilitado e
    // desabilitado não se lê pelo name.
    function ano() {
      var el = form.querySelector('[name="ano"]');
      return parseInt(el ? el.value : (form.dataset.ano || ''), 10);
    }

    form.addEventListener('submit', function (ev) {
      var erro = '', anterior = '', doAno = ano();

      for (var n = 1; n <= 4; n++) {
        var i = valor('bim' + n + '_inicio'), f = valor('bim' + n + '_fim');
        if (!i || !f) {
          erro = 'Informe as datas de início e fim dos quatro bimestres.';
          break;
        }
        if (f < i) {
          erro = 'No ' + rotulo(n) + ', o fim está antes do início.';
          break;
        }
        if (anterior && i <= anterior) {
          erro = 'O ' + rotulo(n) + ' começa antes de o anterior terminar.';
          break;
        }
        var fora = [i, f].filter(function (d) { return parseInt(d.slice(0, 4), 10) !== doAno; });
        if (doAno && fora.length) {
          erro = 'No ' + rotulo(n) + ', a data ' + brasileira(fora[0]) + ' está fora de ' + doAno + '.';
          break;
        }
        anterior = f;
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
