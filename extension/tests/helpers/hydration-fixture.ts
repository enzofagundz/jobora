export interface HydrationPost {
  componentKey: string;
  activityId: string;
  threadActivityId?: string;
}

export function hydrationScript(posts: HydrationPost[]): string {
  const lines: string[] = [];
  const globalIds = [
    ...posts.map((post) => post.activityId),
    '9000000000000000999',
    '9000000000000000998',
  ];

  posts.forEach((post, index) => {
    const key = (100 + index).toString(16);
    const ref = (200 + index).toString(16);
    lines.push(
      `${key}:["$","$L${ref}",null,{"role":"listitem","componentkey":"${post.componentKey}","children":["$L999"]}]`,
    );
    lines.push(
      `${ref}:["$","div",null,{"feedUpdateUrn":{"updateUrnActivityUrn":{"activityUrn":{"activityId":"${post.activityId}"}}},"threadUrn":{"threadUrnActivityThreadUrn":{"activityUrn":{"activityId":"${post.threadActivityId ?? post.activityId}"}}}}]`,
    );
  });

  lines.push(
    `999:["$","div",null,{"feedUpdateUrn":{"updateUrnActivityUrn":{"activityUrn":{"activityId":"${globalIds[0] ?? '9000000000000000001'}"}}},"threadUrn":{"threadUrnActivityThreadUrn":{"activityUrn":{"activityId":"${globalIds[1] ?? '9000000000000000999'}"}}},"other":{"activityId":"${globalIds[2] ?? '9000000000000000998'}"}}]`,
  );

  return `window.__como_rehydration__ = ${JSON.stringify(lines)};`;
}

export function mountHydrationScript(text: string): HTMLScriptElement {
  const script = document.createElement('script');
  script.id = 'rehydrate-data';
  script.type = 'text/javascript';
  script.textContent = text;
  document.head.append(script);
  return script;
}
