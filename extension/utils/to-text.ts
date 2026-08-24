/**
 * Serializa a estrutura de um elemento para texto — semântica byte a byte dos
 * userscripts (linkedin-job-capture.user.js:77-122): BR vira quebra, LI vira
 * "- ", A usa só o texto visível, tags de bloco cercam filhos com quebras,
 * espaços horizontais colapsam, 3+ quebras viram duas e o resultado sofre trim.
 */
const BLOCK_TAGS = new Set([
  'P', 'DIV', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'MAIN', 'ASIDE',
  'UL', 'OL', 'TABLE', 'BLOCKQUOTE', 'PRE', 'FIGURE',
  'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR',
]);

export function toText(root: Node | null | undefined): string {
  if (!root) return '';
  const parts: string[] = [];

  const walk = (node: Node): void => {
    if (node.nodeType === Node.TEXT_NODE) {
      parts.push(node.nodeValue ?? '');
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    const el = node as Element;
    switch (el.tagName) {
      case 'BR':
        parts.push('\n');
        return;
      case 'LI':
        parts.push('- ');
        walkChildren(el);
        parts.push('\n');
        return;
      case 'A':
        parts.push(el.textContent ?? '');
        return;
    }
    if (BLOCK_TAGS.has(el.tagName)) {
      parts.push('\n');
      walkChildren(el);
      parts.push('\n');
      return;
    }
    walkChildren(el);
  };

  const walkChildren = (node: Element): void => {
    for (const child of node.childNodes) walk(child);
  };

  walk(root);

  return parts
    .join('')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/** Corta query e fragment de um href (cleanHref dos userscripts). */
export function cleanHref(href: string | null | undefined): string | null {
  if (!href) return null;
  const cut = href.split('?')[0] ?? href;
  return (cut.split('#')[0] ?? cut) || null;
}
