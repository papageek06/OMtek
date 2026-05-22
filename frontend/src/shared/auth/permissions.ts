import type { User } from '../../api/client'

const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as const

export function hasRole(user: Pick<User, 'roles'> | null | undefined, role: string): boolean {
  return !!user?.roles?.includes(role)
}

export function isAdmin(user: Pick<User, 'roles'> | null | undefined): boolean {
  return !!user?.roles?.some((role) => ADMIN_ROLES.includes(role as typeof ADMIN_ROLES[number]))
}

export function canSeeAdminStock(user: Pick<User, 'roles'> | null | undefined): boolean {
  return isAdmin(user)
}

export function canManageContracts(user: Pick<User, 'roles'> | null | undefined): boolean {
  return isAdmin(user)
}

export function canManageUsers(user: Pick<User, 'roles'> | null | undefined): boolean {
  return isAdmin(user)
}
