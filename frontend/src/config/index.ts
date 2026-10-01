// 共享数值配置（与后端共用 config/*.json），改 JSON 后前端 HMR / 刷新即生效
import realmsJson from '@config/realms.json'
import meditationJson from '@config/meditation.json'
import pillsJson from '@config/pills.json'
import formationsJson from '@config/formations.json'
import techniquesJson from '@config/techniques.json'
import unlockJson from '@config/unlock.json'
import audioJson from '@config/audio.json'
import economyJson from '@config/economy.json'
import shopJson from '@config/shop.json'

export interface Realm {
  id: string
  name: string
  order: number
  lifespan_years: number
  cultivate_rate: number
  stages: string[]
  exp_to_next: number[]
}
export interface MeditationItem {
  id: string
  name: string
  duration_seconds: number
  base_exp: number
}
export interface RetreatItem {
  id: string
  name: string
  duration_hours: number
  base_exp: number
}
export interface Pill {
  id: string
  name: string
  effect_type: string
  effect_value: number | null
  price: number
  unlock_realm: string | null
  max_per_retreat: number | null
}
export interface Formation {
  id: string
  name: string
  effect_type: string
  effect_value: number
  cost_per_use: number
  unlock_realm: string | null
}
export interface Technique {
  id: string
  name: string
  closing_efficiency: number
  unlock_realm: string | null
}

export const realmsConfig = realmsJson as unknown as { start_age_years: number; realms: Realm[] }
export const meditationConfig = meditationJson as unknown as {
  dazuo_base_exp: number
  dazuo_batch_size: number
  dazuo_daily_limit_base: number
  dazuo_daily_limit_per_realm: number
  dazuo_click_interval_ms: number
  dazuo_cooldown_ms: number
  meditation_durations: number[]
  meditation_exp_ratio: number
  meditations: MeditationItem[]
  retreats: RetreatItem[]
  retreat_buff_cap: number
  retreat_early_exit_ratio: number
}
export const pillsConfig = pillsJson as unknown as { pills: Pill[] }
export const formationsConfig = formationsJson as unknown as { formations: Formation[] }
export const techniquesConfig = techniquesJson as unknown as { techniques: Technique[] }

export interface AudioItem {
  src: string
  loop?: boolean
}
export const audioConfig = audioJson as unknown as {
  bgm: Record<string, AudioItem>
  sfx: Record<string, AudioItem>
}

// ==================== 灵石 / 商店 ====================

export interface ShopItemConfig {
  id: string
  name: string
  category: string
  ref_id: string
  price: number
  currency_level: string
  limit: number | null
  unlock: unknown
}

export const economyConfig = economyJson as unknown as {
  spirit_stone_levels: string[]
  spirit_stone_caps: Record<string, number>
  exchange_ratio: number
  exchange_fee_ratio: Record<string, number>
}
export const shopConfig = shopJson as unknown as { items: ShopItemConfig[] }

// 灵石品级中文名（展示用文案，取自设计文档「下品/中品/上品/极品」）
export const spiritStoneLabels: Record<string, string> = {
  low: '下品',
  mid: '中品',
  high: '上品',
  top: '极品',
}

export function realmOf(realmId: string): Realm | undefined {
  return realmsConfig.realms.find((r) => r.id === realmId)
}

export function realmOrder(realmId: string): number {
  return realmOf(realmId)?.order ?? 0
}

// ==================== 功能解锁 ====================

export interface UnlockCondition {
  name: string
  realm_id: string
  stage: number
}

export const unlockConfig = unlockJson as unknown as Record<string, UnlockCondition>

// 功能是否已解锁（未在 unlock.json 登记的功能默认解锁）
export function isFeatureUnlocked(featureId: string, realmId: string, stageIndex: number): boolean {
  const cond = unlockConfig[featureId]
  if (!cond) return true
  const pOrder = realmOrder(realmId)
  const cOrder = realmOrder(cond.realm_id)
  if (pOrder > cOrder) return true
  if (pOrder < cOrder) return false
  return stageIndex >= cond.stage - 1
}

// 未解锁时的锁定提示文案，如「练气三层解锁」
export function unlockHint(featureId: string): string {
  const cond = unlockConfig[featureId]
  if (!cond) return ''
  const realm = realmOf(cond.realm_id)
  const stageName = realm?.stages[cond.stage - 1] ?? `${cond.stage}层`
  return `${realm?.name ?? ''}${stageName}解锁`
}
