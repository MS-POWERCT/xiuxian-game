import type { PlayerStatus } from '@/api/game'

// 状态 → 禁用提示文案（idle 表示可正常操作，返回空串）
const HINTS: Record<PlayerStatus, string> = {
  idle: '',
  retreating: '闭关中，暂不能执行其他操作',
  meditating: '冥想中，暂不能执行其他操作',
  exploring: '探索中，暂不能执行其他操作',
  dead: '已死亡，仅可转世重修',
}

export function statusHint(status: PlayerStatus): string {
  return HINTS[status] ?? ''
}