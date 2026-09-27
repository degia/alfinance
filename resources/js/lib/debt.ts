import type { DebtFormData } from '@/types';

/**
 * Form utang dipakai bersama oleh halaman Create, Edit, dan form inline di
 * Index, jadi bentuk default-nya di satu tempat saja.
 */
export function emptyDebtForm(): DebtFormData {
    return {
        direction: 'payable',
        counterparty: '',
        principal: '',
        interest_rate: '',
        start_date: '',
        due_date: '',
        term_count: '',
        include_in_net_worth: true,
        note: '',
        account_id: '',
    };
}
