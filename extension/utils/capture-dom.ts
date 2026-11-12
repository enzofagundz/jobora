import type { CaptureBlock } from '@/types/capture';

const DROP_TAGS = [
  'script',
  'style',
  'iframe',
  'frame',
  'frameset',
  'object',
  'embed',
  'applet',
  'form',
  'input',
  'button',
  'select',
  'option',
  'textarea',
  'link',
  'meta',
  'base',
  'svg',
  'math',
  'template',
  'noscript',
  'img',
  'video',
  'audio',
  'source',
  'picture',
  'canvas',
].join(',');

const KEPT_ATTRIBUTES = new Set(['href', 'title', 'alt', 'lang', 'dir']);

/** Copia um bloco para HTML inerte, sem tags executáveis, imagens ou dados internos. */
export function createCaptureBlockElement(
  element: Element,
  block: CaptureBlock,
  tagName?: 'article' | 'section',
): Element {
  let clone = element.cloneNode(true) as Element;

  for (const bad of clone.querySelectorAll(DROP_TAGS)) bad.remove();

  if (tagName && clone.tagName.toLowerCase() !== tagName) {
    const wrapper = document.createElement(tagName);
    wrapper.innerHTML = clone.innerHTML;
    clone = wrapper;
  }

  for (const node of [clone, ...clone.querySelectorAll('*')]) {
    for (const attribute of Array.from(node.attributes)) {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('data-') || !KEPT_ATTRIBUTES.has(name)) {
        node.removeAttribute(attribute.name);
        continue;
      }
      if (name === 'href') {
        const href = safeHref(attribute.value);
        if (href) node.setAttribute('href', href);
        else node.removeAttribute('href');
      }
    }
  }

  return clone;
}

/** Serializa o bloco aplicando as relações atuais card/detalhe. */
export function serializeCaptureBlock(element: Element, block: CaptureBlock): string {
  element.setAttribute('data-block-id', block.block_id);
  element.setAttribute('data-kind', block.kind);
  if (block.platform_id === null) {
    element.removeAttribute('data-platform-id');
  } else {
    element.setAttribute('data-platform-id', block.platform_id);
  }
  if (block.permalink === null) {
    element.removeAttribute('data-permalink');
  } else {
    element.setAttribute('data-permalink', block.permalink);
  }
  element.removeAttribute('data-card-block-id');
  element.removeAttribute('data-detail-block-id');

  if (block.card_block_id !== null) {
    element.setAttribute('data-card-block-id', block.card_block_id);
  }
  if (block.detail_block_id !== null) {
    element.setAttribute('data-detail-block-id', block.detail_block_id);
  }

  return element.outerHTML;
}

export function snapshotCaptureBlock(element: Element, block: CaptureBlock): string {
  return serializeCaptureBlock(createCaptureBlockElement(element, block), block);
}

/** Monta a raiz única do envelope com um elemento por bloco, na ordem recebida. */
export function buildCaptureDocument(surface: string, blocksHtml: string[]): string {
  const root = document.createElement('main');
  root.setAttribute('data-capture-surface', surface);
  root.setAttribute('data-capture-version', '1');

  for (const html of blocksHtml) {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const node = holder.firstElementChild;
    if (node) root.append(node);
  }

  return root.outerHTML;
}

export function safeHref(raw: string): string | null {
  try {
    const url = new URL(raw, location.origin);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
    return url.href;
  } catch {
    return null;
  }
}
