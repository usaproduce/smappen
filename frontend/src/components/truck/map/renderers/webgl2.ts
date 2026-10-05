// Truck Planner map engine - the WebGL2 renderer (docs/truck-planner/05_FRONTEND.md 5.7 and 5.8).
//
// One static mesh, one byte per cell, one draw call. The positions and indices are uploaded once per
// region; a tick of the hour control uploads N bytes expanded to one per vertex; a camera move
// changes two uniforms. The colour table is a 256 x 1 texture, so a layer or theme change is one
// small upload.
//
// The renderer keeps the typed arrays it was given, because a lost context comes back empty: on
// `webglcontextrestored` the program, the buffers and the texture are rebuilt from them.

import type { HexMesh } from '../../../../utils/truck/map/mesh';
import { clipTransform } from '../../../../utils/truck/map/viewport';
import type { Renderer, Viewport } from '../types';

const VERTEX_SHADER = `#version 300 es
in vec2 a_pos;
in float a_val;
uniform vec2 u_scale;
uniform vec2 u_offset;
out float v_val;
void main() {
  v_val = a_val;
  gl_Position = vec4(a_pos * u_scale + u_offset, 0.0, 1.0);
}
`;

const FRAGMENT_SHADER = `#version 300 es
precision mediump float;
in float v_val;
uniform sampler2D u_lut;
uniform float u_alpha;
out vec4 o;
void main() {
  if (v_val <= 0.0) discard;
  vec4 c = texture(u_lut, vec2(v_val * (255.0 / 256.0) + 0.5 / 256.0, 0.5));
  o = vec4(c.rgb * c.a * u_alpha, c.a * u_alpha);
}
`;

const CONTEXT_OPTIONS: WebGLContextAttributes = {
  alpha: true,
  premultipliedAlpha: true,
  antialias: false,
  depth: false,
  stencil: false,
};

const VERTICES_PER_CELL = 6;

export interface Webgl2Events {
  /** The browser took the context away. Nothing is drawn until it comes back. */
  onLost(): void;
  /** The context is back. `ok` is false when the renderer could not be set up again, twice. */
  onRestored(ok: boolean): void;
}

interface GlObjects {
  program: WebGLProgram;
  vao: WebGLVertexArrayObject;
  positions: WebGLBuffer;
  indices: WebGLBuffer;
  values: WebGLBuffer;
  lut: WebGLTexture;
  uScale: WebGLUniformLocation | null;
  uOffset: WebGLUniformLocation | null;
  uAlpha: WebGLUniformLocation | null;
  uLut: WebGLUniformLocation | null;
}

function compile(gl: WebGL2RenderingContext, type: number, source: string): WebGLShader {
  const shader = gl.createShader(type);
  if (shader === null) throw new Error('webgl2: no shader');
  gl.shaderSource(shader, source);
  gl.compileShader(shader);
  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    const log = gl.getShaderInfoLog(shader) ?? '';
    gl.deleteShader(shader);
    throw new Error('webgl2: shader did not compile. ' + log);
  }
  return shader;
}

/**
 * A WebGL2 renderer on a canvas, or null when the browser gives no WebGL2 context or the renderer
 * cannot be set up on it (tried twice). After a null the canvas may be bound to a WebGL context, so
 * a 2D renderer needs a canvas of its own.
 */
export function createWebgl2Renderer(canvas: HTMLCanvasElement, events?: Webgl2Events): Renderer | null {
  let gl: WebGL2RenderingContext | null = null;
  try {
    gl = canvas.getContext('webgl2', CONTEXT_OPTIONS);
  } catch {
    gl = null;
  }
  if (gl === null) return null;
  const ctx = gl;

  let objects: GlObjects | null = null;
  let lost = false;
  let disposed = false;

  // What the GL side is rebuilt from.
  let mesh: HexMesh | null = null;
  let lut: Uint8Array | null = null;
  // One byte per vertex: the cell bytes, each six times.
  let expanded = new Uint8Array(0);
  let opacity = 1;
  const transform = new Float32Array(4);

  function setup(): void {
    const vs = compile(ctx, ctx.VERTEX_SHADER, VERTEX_SHADER);
    const fs = compile(ctx, ctx.FRAGMENT_SHADER, FRAGMENT_SHADER);
    const program = ctx.createProgram();
    if (program === null) throw new Error('webgl2: no program');
    ctx.attachShader(program, vs);
    ctx.attachShader(program, fs);
    ctx.bindAttribLocation(program, 0, 'a_pos');
    ctx.bindAttribLocation(program, 1, 'a_val');
    ctx.linkProgram(program);
    ctx.deleteShader(vs);
    ctx.deleteShader(fs);
    if (!ctx.getProgramParameter(program, ctx.LINK_STATUS)) {
      const log = ctx.getProgramInfoLog(program) ?? '';
      ctx.deleteProgram(program);
      throw new Error('webgl2: program did not link. ' + log);
    }

    const vao = ctx.createVertexArray();
    const positions = ctx.createBuffer();
    const indices = ctx.createBuffer();
    const values = ctx.createBuffer();
    const texture = ctx.createTexture();
    if (vao === null || positions === null || indices === null || values === null || texture === null) {
      throw new Error('webgl2: no buffers');
    }

    ctx.bindVertexArray(vao);
    ctx.bindBuffer(ctx.ARRAY_BUFFER, positions);
    ctx.enableVertexAttribArray(0);
    ctx.vertexAttribPointer(0, 2, ctx.FLOAT, false, 0, 0);
    ctx.bindBuffer(ctx.ARRAY_BUFFER, values);
    ctx.enableVertexAttribArray(1);
    ctx.vertexAttribPointer(1, 1, ctx.UNSIGNED_BYTE, true, 0, 0);
    ctx.bindBuffer(ctx.ELEMENT_ARRAY_BUFFER, indices);
    ctx.bindVertexArray(null);

    ctx.activeTexture(ctx.TEXTURE0);
    ctx.bindTexture(ctx.TEXTURE_2D, texture);
    ctx.texParameteri(ctx.TEXTURE_2D, ctx.TEXTURE_MIN_FILTER, ctx.NEAREST);
    ctx.texParameteri(ctx.TEXTURE_2D, ctx.TEXTURE_MAG_FILTER, ctx.NEAREST);
    ctx.texParameteri(ctx.TEXTURE_2D, ctx.TEXTURE_WRAP_S, ctx.CLAMP_TO_EDGE);
    ctx.texParameteri(ctx.TEXTURE_2D, ctx.TEXTURE_WRAP_T, ctx.CLAMP_TO_EDGE);
    ctx.pixelStorei(ctx.UNPACK_ALIGNMENT, 1);
    ctx.texImage2D(ctx.TEXTURE_2D, 0, ctx.RGBA, 256, 1, 0, ctx.RGBA, ctx.UNSIGNED_BYTE, new Uint8Array(1024));

    ctx.disable(ctx.DEPTH_TEST);
    ctx.disable(ctx.CULL_FACE);
    ctx.enable(ctx.BLEND);
    ctx.blendFunc(ctx.ONE, ctx.ONE_MINUS_SRC_ALPHA);
    ctx.clearColor(0, 0, 0, 0);

    objects = {
      program,
      vao,
      positions,
      indices,
      values,
      lut: texture,
      uScale: ctx.getUniformLocation(program, 'u_scale'),
      uOffset: ctx.getUniformLocation(program, 'u_offset'),
      uAlpha: ctx.getUniformLocation(program, 'u_alpha'),
      uLut: ctx.getUniformLocation(program, 'u_lut'),
    };
    if (ctx.isContextLost()) throw new Error('webgl2: context lost during setup');
  }

  function uploadMesh(): void {
    if (objects === null || mesh === null) return;
    ctx.bindBuffer(ctx.ARRAY_BUFFER, objects.positions);
    ctx.bufferData(ctx.ARRAY_BUFFER, mesh.positions, ctx.STATIC_DRAW);
    ctx.bindVertexArray(objects.vao);
    ctx.bindBuffer(ctx.ELEMENT_ARRAY_BUFFER, objects.indices);
    ctx.bufferData(ctx.ELEMENT_ARRAY_BUFFER, mesh.indices, ctx.STATIC_DRAW);
    ctx.bindVertexArray(null);
    ctx.bindBuffer(ctx.ARRAY_BUFFER, objects.values);
    ctx.bufferData(ctx.ARRAY_BUFFER, expanded, ctx.DYNAMIC_DRAW);
  }

  function uploadLut(): void {
    if (objects === null || lut === null) return;
    ctx.activeTexture(ctx.TEXTURE0);
    ctx.bindTexture(ctx.TEXTURE_2D, objects.lut);
    ctx.pixelStorei(ctx.UNPACK_ALIGNMENT, 1);
    ctx.texSubImage2D(ctx.TEXTURE_2D, 0, 0, 0, 256, 1, ctx.RGBA, ctx.UNSIGNED_BYTE, lut);
  }

  /** Set up from nothing; a second try when the first fails. False when both failed. */
  function setupTwice(): boolean {
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        objects = null;
        setup();
        uploadMesh();
        uploadLut();
        return true;
      } catch {
        objects = null;
      }
    }
    return false;
  }

  function onContextLost(event: Event): void {
    // Without this the browser never restores the context.
    event.preventDefault();
    if (disposed) return;
    lost = true;
    objects = null;
    try {
      events?.onLost();
    } catch {
      // the layer reports its own failures
    }
  }

  function onContextRestored(): void {
    if (disposed) return;
    lost = false;
    const ok = setupTwice();
    try {
      events?.onRestored(ok);
    } catch {
      // the layer reports its own failures
    }
  }

  canvas.addEventListener('webglcontextlost', onContextLost, false);
  canvas.addEventListener('webglcontextrestored', onContextRestored, false);

  if (!setupTwice()) {
    canvas.removeEventListener('webglcontextlost', onContextLost, false);
    canvas.removeEventListener('webglcontextrestored', onContextRestored, false);
    disposed = true;
    try {
      ctx.getExtension('WEBGL_lose_context')?.loseContext();
    } catch {
      // nothing to release
    }
    return null;
  }

  return {
    kind: 'webgl2',

    setMesh(next: HexMesh): void {
      mesh = next;
      if (expanded.length !== next.n * VERTICES_PER_CELL) expanded = new Uint8Array(next.n * VERTICES_PER_CELL);
      else expanded.fill(0);
      if (!lost) uploadMesh();
    },

    setLut(next: Uint8Array): void {
      lut = next;
      if (!lost) uploadLut();
    },

    setValues(values: Uint8Array): void {
      const n = mesh === null ? 0 : mesh.n;
      if (values.length < n) return;
      let at = 0;
      for (let c = 0; c < n; c++) {
        const v = values[c];
        expanded[at] = v;
        expanded[at + 1] = v;
        expanded[at + 2] = v;
        expanded[at + 3] = v;
        expanded[at + 4] = v;
        expanded[at + 5] = v;
        at += 6;
      }
      if (lost || objects === null) return;
      ctx.bindBuffer(ctx.ARRAY_BUFFER, objects.values);
      ctx.bufferSubData(ctx.ARRAY_BUFFER, 0, expanded);
    },

    setOpacity(alpha: number): void {
      opacity = alpha;
    },

    resize(width: number, height: number, dpr: number): void {
      const w = Math.max(1, Math.round(width * dpr));
      const h = Math.max(1, Math.round(height * dpr));
      if (canvas.width !== w) canvas.width = w;
      if (canvas.height !== h) canvas.height = h;
      canvas.style.width = width + 'px';
      canvas.style.height = height + 'px';
    },

    render(vp: Viewport): void {
      if (lost || disposed || objects === null) return;
      ctx.viewport(0, 0, canvas.width, canvas.height);
      ctx.clear(ctx.COLOR_BUFFER_BIT);
      if (mesh === null || mesh.n === 0 || lut === null || !(opacity > 0) || !(vp.width > 0) || !(vp.height > 0)) return;
      clipTransform(vp, transform);
      ctx.useProgram(objects.program);
      ctx.uniform2f(objects.uScale, transform[0], transform[1]);
      ctx.uniform2f(objects.uOffset, transform[2], transform[3]);
      ctx.uniform1f(objects.uAlpha, opacity);
      ctx.activeTexture(ctx.TEXTURE0);
      ctx.bindTexture(ctx.TEXTURE_2D, objects.lut);
      ctx.uniform1i(objects.uLut, 0);
      ctx.bindVertexArray(objects.vao);
      ctx.drawElements(ctx.TRIANGLES, mesh.indices.length, ctx.UNSIGNED_INT, 0);
      ctx.bindVertexArray(null);
    },

    dispose(): void {
      if (disposed) return;
      disposed = true;
      canvas.removeEventListener('webglcontextlost', onContextLost, false);
      canvas.removeEventListener('webglcontextrestored', onContextRestored, false);
      try {
        if (objects !== null && !lost) {
          ctx.deleteProgram(objects.program);
          ctx.deleteVertexArray(objects.vao);
          ctx.deleteBuffer(objects.positions);
          ctx.deleteBuffer(objects.indices);
          ctx.deleteBuffer(objects.values);
          ctx.deleteTexture(objects.lut);
        }
        // Give the context back now: a page may only hold a few, and React mounts effects twice in development.
        ctx.getExtension('WEBGL_lose_context')?.loseContext();
      } catch {
        // a context that is already gone has nothing to release
      }
      objects = null;
      mesh = null;
      lut = null;
    },
  };
}
